<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates actions that stay administrator-only even on a server a member has
 * been assigned to: the web terminal, server lifecycle (create/delete/reboot/
 * token rotation), system users, firewall/fail2ban, and SSH key deployment.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return $next($request);
    }
}
