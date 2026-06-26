<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CloudflareToken;
use App\Models\DnsRecord;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates Cloudflare DNS record lifecycle for applications.
 *
 * Bridges the CloudflareService (HTTP API client) and the DnsRecord model
 * (DB tracking). All operations are non-blocking — a Cloudflare failure
 * never prevents app provisioning or destruction.
 */
class DnsService
{
    public function __construct(private CloudflareService $cloudflare) {}

    /**
     * Create an A record for the app's domain pointing to the server's public IP.
     *
     * Non-blocking: if the zone isn't found or the API fails, returns false
     * without throwing. The app still provisions — DNS can be fixed later.
     *
     * @return bool Whether the record was created successfully.
     */
    public function provisionDomain(Application $app, CloudflareToken $token): bool
    {
        if (! $app->domain || ! $app->server->public_ip) {
            return false;
        }

        try {
            $zoneId = $this->cloudflare->findZoneForDomain($token->api_token, $app->domain);

            if (! $zoneId) {
                Log::info("DNS: no Cloudflare zone found for {$app->domain}, skipping A record.");

                return false;
            }

            $recordId = $this->cloudflare->createRecord($token->api_token, $zoneId, [
                'type' => 'A',
                'name' => $app->domain,
                'content' => $app->server->public_ip,
                'proxied' => false,
                'ttl' => 1,
            ]);

            if (! $recordId) {
                Log::warning("DNS: failed to create A record for {$app->domain}.");

                return false;
            }

            DnsRecord::create([
                'application_id' => $app->id,
                'cloudflare_token_id' => $token->id,
                'zone_id' => $zoneId,
                'record_id' => $recordId,
                'type' => 'A',
                'name' => $app->domain,
                'content' => $app->server->public_ip,
                'proxied' => false,
                'ttl' => 1,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("DNS: provisionDomain failed for {$app->domain}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Delete all Cloudflare DNS records owned by this app.
     *
     * Non-blocking: always removes the DB rows even if the CF API call fails,
     * so the app can be destroyed cleanly.
     */
    public function teardownDomain(Application $app, CloudflareToken $token): void
    {
        $records = $app->dnsRecords()->get();

        foreach ($records as $record) {
            try {
                $this->cloudflare->deleteRecord($token->api_token, $record->zone_id, $record->record_id);
            } catch (\Throwable $e) {
                Log::warning("DNS: failed to delete CF record {$record->record_id}: {$e->getMessage()}");
            }
        }

        // Always clean up DB rows, even if CF delete failed.
        $app->dnsRecords()->delete();
    }
}
