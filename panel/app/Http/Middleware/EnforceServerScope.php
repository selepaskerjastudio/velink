<?php

namespace App\Http\Middleware;

use App\Contracts\BelongsToServer;
use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts members to the servers assigned to them.
 *
 * Most panel routes are nested under {server} or bind a model that belongs to
 * one, so resolving the owning server from the route parameters covers the
 * majority of the surface in a single place. Anything this middleware cannot
 * tie to a server must be listed in SERVERLESS_ROUTES; unlisted routes are
 * denied, so a route added later fails closed rather than silently open.
 *
 * Must run after SubstituteBindings — it reads bound model instances, not raw
 * route strings. See bootstrap/app.php for the priority wiring.
 */
class EnforceServerScope
{
    /**
     * Panel routes that legitimately have no owning server: global lists that
     * scope their own queries, and account-level settings that are already
     * scoped to the authenticated user by their controllers.
     */
    private const SERVERLESS_ROUTES = [
        // Global lists — these scope their own queries by visible servers.
        'dashboard',
        'servers.index',
        'servers.create',
        'servers.store',
        'audit-logs.index',
        'alerts.index',
        'github.repos',
        'logout',

        // Account-scoped resources — ownership enforced in their controllers.
        'git-credentials.index',
        'git-credentials.store',
        'git-credentials.destroy',
        'git-credentials.oauth.redirect',
        'git-credentials.oauth.callback',
        'ssh-keys.index',
        'ssh-keys.store',
        'ssh-keys.destroy',
        'cloudflare.index',
        'cloudflare.store',
        'cloudflare.destroy',
        'notifications.index',
        'notifications.store',
        'notifications.toggle',
        'notifications.destroy',

        // User management — admin-only (enforced by the 'admin' middleware on
        // these routes), and roster-wide rather than scoped to any one server.
        'users.index',
        'users.update',
        'users.servers.sync',
        'users.destroy',
        'invitations.store',
        'invitations.destroy',

        // Personal settings.
        'settings.redirect',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'password.edit',
        'password.update',
        'password.confirm',
        'password.confirm.store',
        'appearance',
        'two-factor.show',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.recovery-codes',
        'two-factor.destroy',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admins are not resource-scoped. Returning early also means an
        // unclassified route stays reachable for them, so a mistake shows up
        // as a member-only 403 rather than breaking the panel outright —
        // RouteCoverageTest is what actually catches the omission.
        if ($user?->isAdmin()) {
            return $next($request);
        }

        $server = $this->resolveServer($request->route());

        if ($server instanceof Server) {
            abort_unless(
                $user && $user->canAccessServer($server),
                403,
                'You do not have access to this server.'
            );

            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::SERVERLESS_ROUTES, true)) {
            return $next($request);
        }

        Log::warning('EnforceServerScope denied an unclassified route', [
            'route' => $request->route()?->getName(),
            'uri' => $request->route()?->uri(),
            'user_id' => $user?->id,
        ]);

        abort(403, 'This action is not available.');
    }

    /**
     * Find the server a request targets by inspecting its bound route models.
     * {server} is always the first parameter on nested routes, so the first
     * match wins.
     */
    private function resolveServer(?RoutingRoute $route): ?Server
    {
        foreach (($route?->parameters() ?? []) as $parameter) {
            if ($parameter instanceof Server) {
                return $parameter;
            }

            if ($parameter instanceof BelongsToServer) {
                return $parameter->owningServer();
            }
        }

        return null;
    }
}
