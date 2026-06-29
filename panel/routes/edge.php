<?php

use App\Http\Controllers\Internal\EdgeProxyController;
use Illuminate\Support\Facades\Route;

/*
| Endpoint hit by the edge proxy (Caddy) for on-demand TLS authorization. It is
| deliberately NOT behind VerifyGatewaySecret — Caddy's `ask` is a plain GET
| that cannot carry the gateway secret. It is gated instead by config + a static
| query secret (VELINK_EDGE_PROXY_ASK_SECRET) and only authorizes domains that
| belong to an edge-backed server.
*/

Route::get('caddy/authorize', [EdgeProxyController::class, 'check'])->name('internal.caddy.authorize');
