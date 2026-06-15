<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates internal requests from the Go gateway using a shared secret
 * (X-Gateway-Secret), compared in constant time. These routes carry no session.
 */
class VerifyGatewaySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.gateway.secret');
        $provided = (string) $request->header('X-Gateway-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid gateway secret.');
        }

        return $next($request);
    }
}
