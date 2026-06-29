<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy on-demand TLS gate. Caddy calls this before issuing a certificate for a
 * hostname; we authorize only domains that belong to an edge-backed server, so
 * a stranger pointing DNS at the edge can't trigger unbounded cert issuance.
 *
 * Not behind VerifyGatewaySecret (Caddy's `ask` GET can't send it); protected
 * instead by a static secret in the query string. Configure Caddy's ask URL as
 * http://panel/internal/caddy/authorize?key=SECRET — Caddy appends &domain=.
 */
class EdgeProxyController extends Controller
{
    public function check(Request $request): Response
    {
        if (config('velink.edge_proxy.driver') === 'none') {
            abort(404);
        }

        $secret = config('velink.edge_proxy.ask_secret');
        if ($secret && ! hash_equals((string) $secret, (string) $request->query('key'))) {
            abort(403);
        }

        $domain = (string) $request->query('domain');

        $authorized = $domain !== '' && Application::query()
            ->where('domain', $domain)
            ->whereHas('server', fn ($q) => $q->where('uses_edge_proxy', true))
            ->exists();

        return response('', $authorized ? 200 : 403);
    }
}
