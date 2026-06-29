<?php

namespace App\Services\Edge;

/**
 * A reverse-proxy edge that fronts target servers with no public IP.
 *
 * Implementations push per-application routes so a domain "just works" without
 * touching the edge by hand. Routes are keyed by the application UUID so adding
 * the same app again is an idempotent upsert. All operations are non-blocking —
 * an edge failure must never break app provisioning or deletion (see DnsService
 * for the same contract).
 */
interface EdgeProxy
{
    /**
     * Create or replace the route for an app: requests for $host are proxied to
     * $upstream (an "ip:port" dial string).
     */
    public function addRoute(string $host, string $upstream, string $appUuid): void;

    /** Remove the app's route, if any. */
    public function removeRoute(string $appUuid): void;
}
