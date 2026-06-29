<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Panel-side Caddy Admin API client.
 *
 * Like CloudflareService, all calls happen here (in Laravel) — the panel pushes
 * routes to Caddy's admin endpoint (bound to an internal IP). Routes carry a
 * stable `@id` of "velink-app-{uuid}" so they can be replaced or removed by ID.
 * Failures are logged and swallowed; they never bubble up to the request.
 */
class CaddyEdgeProxy implements EdgeProxy
{
    public function __construct(
        private ?string $adminUrl,
        private string $server = 'edge',
    ) {}

    public function addRoute(string $host, string $upstream, string $appUuid): void
    {
        if (! $this->adminUrl) {
            Log::warning('Edge: VELINK_EDGE_PROXY_ADMIN_URL is not set; skipping addRoute.');

            return;
        }

        // Upsert: drop any existing route for this app first (best-effort), then
        // append the fresh one. Caddy has no atomic "replace by id" for an array
        // element, so delete-then-add keeps it idempotent.
        $this->deleteById($this->routeId($appUuid));

        try {
            Http::timeout(10)
                ->post("{$this->base()}/config/apps/http/servers/{$this->server}/routes", [
                    '@id' => $this->routeId($appUuid),
                    'match' => [['host' => [$host]]],
                    'handle' => [[
                        'handler' => 'reverse_proxy',
                        'upstreams' => [['dial' => $upstream]],
                    ]],
                ])
                ->throw();
        } catch (\Throwable $e) {
            Log::error("Edge: addRoute failed for {$host} ({$appUuid}): {$e->getMessage()}");
        }
    }

    public function removeRoute(string $appUuid): void
    {
        if (! $this->adminUrl) {
            return;
        }

        $this->deleteById($this->routeId($appUuid));
    }

    /**
     * Delete a config node by its `@id`. Best-effort and idempotent: a missing
     * id (Caddy answers non-2xx) is treated as success.
     */
    private function deleteById(string $id): void
    {
        try {
            Http::timeout(10)->delete("{$this->base()}/id/{$id}");
        } catch (\Throwable $e) {
            Log::debug("Edge: deleteById {$id} failed (ignored): {$e->getMessage()}");
        }
    }

    private function routeId(string $appUuid): string
    {
        return "velink-app-{$appUuid}";
    }

    private function base(): string
    {
        return rtrim((string) $this->adminUrl, '/');
    }
}
