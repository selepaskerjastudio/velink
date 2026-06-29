<?php

namespace App\Services\Edge;

/**
 * No-op edge proxy used when VELINK_EDGE_PROXY_DRIVER=none (the default).
 *
 * Keeps call sites driver-agnostic: they always resolve an EdgeProxy and call
 * it; with the feature off, nothing happens.
 */
class NullEdgeProxy implements EdgeProxy
{
    public function addRoute(string $host, string $upstream, string $appUuid): void {}

    public function removeRoute(string $appUuid): void {}
}
