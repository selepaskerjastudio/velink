<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Panel-side Cloudflare API client.
 *
 * All Cloudflare API calls happen here (in Laravel), NOT via agent shell jobs.
 * This keeps the API token on the panel — it never transits the gateway/agent.
 * Only the resulting DNS records exist on the server.
 */
class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Verify a Cloudflare API token.
     *
     * @return array{valid: bool, message?: string}
     */
    public function verifyToken(string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get(self::API_BASE.'/user/tokens/verify');

            if ($response->json('success') === true) {
                return ['valid' => true];
            }

            $errors = $response->json('errors', []);
            $message = $errors[0]['message'] ?? 'Token verification failed.';

            return ['valid' => false, 'message' => $message];
        } catch (ConnectionException) {
            return ['valid' => false, 'message' => 'Unable to reach Cloudflare API.'];
        }
    }

    /**
     * List all zones accessible by the token.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function listZones(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/zones', ['per_page' => 50]);

        if (! $response->json('success')) {
            return [];
        }

        return collect($response->json('result', []))
            ->map(fn ($zone) => ['id' => $zone['id'], 'name' => $zone['name']])
            ->all();
    }

    /**
     * Find the Cloudflare zone ID that manages a given domain.
     *
     * Walks the domain labels from the most-specific to the root, returning the
     * first zone that matches. E.g. for "app.sub.example.com" it checks
     * "app.sub.example.com", "sub.example.com", "example.com", "com".
     */
    public function findZoneForDomain(string $token, string $domain): ?string
    {
        $zones = $this->listZones($token);

        if (empty($zones)) {
            return null;
        }

        $zoneNames = collect($zones)->pluck('name')->all();
        $parts = explode('.', $domain);

        // Walk from full domain toward root, checking each suffix.
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            foreach ($zones as $zone) {
                if ($zone['name'] === $candidate) {
                    return $zone['id'];
                }
            }
        }

        return null;
    }

    /**
     * Create a DNS record in a zone.
     *
     * @param  array{type: string, name: string, content: string, proxied: bool, ttl: int}  $data
     * @return string|null The record ID, or null on failure.
     */
    public function createRecord(string $token, string $zoneId, array $data): ?string
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->post(self::API_BASE."/zones/{$zoneId}/dns_records", $data);

        if (! $response->json('success')) {
            return null;
        }

        return $response->json('result.id');
    }

    /**
     * Delete a DNS record by its Cloudflare record ID.
     */
    public function deleteRecord(string $token, string $zoneId, string $recordId): bool
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->delete(self::API_BASE."/zones/{$zoneId}/dns_records/{$recordId}");

        return $response->json('success') === true;
    }

    /**
     * List DNS records for a zone (optionally filtered by name).
     *
     * @return array<int, array{id: string, type: string, name: string, content: string, proxied: bool, ttl: int}>
     */
    public function listRecords(string $token, string $zoneId, ?string $name = null): array
    {
        $params = ['per_page' => 100];
        if ($name) {
            $params['name'] = $name;
        }

        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE."/zones/{$zoneId}/dns_records", $params);

        if (! $response->json('success')) {
            return [];
        }

        return collect($response->json('result', []))
            ->map(fn ($r) => [
                'id' => $r['id'],
                'type' => $r['type'],
                'name' => $r['name'],
                'content' => $r['content'],
                'proxied' => $r['proxied'] ?? false,
                'ttl' => $r['ttl'] ?? 1,
            ])
            ->all();
    }
}
