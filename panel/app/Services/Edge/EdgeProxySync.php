<?php

namespace App\Services\Edge;

use App\Models\Application;

/**
 * Coordinates edge-proxy routes around the application lifecycle.
 *
 * Centralises the native-vs-edge branch so controllers don't repeat it: every
 * method is a no-op unless the app's server is flagged `uses_edge_proxy`. The
 * underlying EdgeProxy is itself a no-op when the feature is disabled, so this
 * is safe to call unconditionally.
 */
class EdgeProxySync
{
    public function __construct(private EdgeProxy $proxy) {}

    /** Whether the app's server is fronted by the edge proxy. */
    public function isEdge(Application $app): bool
    {
        return (bool) $app->server?->uses_edge_proxy;
    }

    /** App was just provisioned (or re-provisioned) — register its route. */
    public function onProvisioned(Application $app): void
    {
        if (! $this->isEdge($app) || ! $app->domain) {
            return;
        }

        $this->proxy->addRoute($app->domain, $this->upstream($app), $app->uuid);
    }

    /** Domain changed — re-point the route, or drop it if the domain was cleared. */
    public function onDomainChanged(Application $app): void
    {
        if (! $this->isEdge($app)) {
            return;
        }

        if ($app->domain) {
            // Route is keyed by app uuid, so addRoute replaces the old host.
            $this->proxy->addRoute($app->domain, $this->upstream($app), $app->uuid);
        } else {
            $this->proxy->removeRoute($app->uuid);
        }
    }

    /** App is being deleted — remove its route. */
    public function onDeleted(Application $app): void
    {
        if (! $this->isEdge($app)) {
            return;
        }

        $this->proxy->removeRoute($app->uuid);
    }

    /** Edge servers are reached over their internal IP; fall back to public. */
    private function upstream(Application $app): string
    {
        $ip = $app->server->private_ip ?: $app->server->public_ip;

        return "{$ip}:80";
    }
}
