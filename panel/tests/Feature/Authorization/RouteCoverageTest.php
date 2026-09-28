<?php

use App\Http\Middleware\EnforceServerScope;
use Illuminate\Support\Facades\Route;

/**
 * Structural guards, not behavioural ones.
 *
 * EnforceServerScope lets admins through before it classifies anything, so a
 * route someone forgets to scope stays perfectly usable for the person who
 * added it — the mistake only surfaces as a 403 for members, in production.
 * These tests are what actually catch that, at author time.
 */
test('no authenticated route uses bare auth instead of the panel group', function () {
    $unclassified = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => in_array('panel', $route->middleware(), true))
        ->map(fn ($route) => ($route->getName() ?? '(unnamed)').'  '.$route->uri())
        ->values();

    expect($unclassified->all())->toBe([], implode("\n", array_merge(
        ["These routes use bare 'auth'. Use the 'panel' group so access scoping applies:"],
        $unclassified->all(),
    )));
});

test('every panel route is either server-resolvable or on the serverless allowlist', function () {
    $allowlist = (function () {
        $reflection = new ReflectionClass(EnforceServerScope::class);

        return $reflection->getConstant('SERVERLESS_ROUTES');
    })();

    // A route is server-resolvable when one of its parameters can carry a
    // Server: either {server} itself, or a model implementing BelongsToServer.
    // Matching on the parameter name is enough here — the middleware does the
    // real type-based resolution at runtime.
    $serverBoundParameters = [
        'server', 'application', 'backup', 'cronJob', 'database', 'databaseUser',
        'deployment', 'dnsRecord', 'service', 'systemUser', 'worker', 'rule',
    ];

    $unclassified = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('panel', $route->middleware(), true))
        ->reject(function ($route) use ($allowlist, $serverBoundParameters) {
            if (in_array($route->getName(), $allowlist, true)) {
                return true;
            }

            return (bool) array_intersect($route->parameterNames(), $serverBoundParameters);
        })
        ->map(fn ($route) => ($route->getName() ?? '(unnamed)').'  '.$route->uri())
        ->values();

    expect($unclassified->all())->toBe([], implode("\n", array_merge(
        ['These panel routes resolve to no server and are not on the allowlist,'],
        ['so members get a 403. Add them to EnforceServerScope::SERVERLESS_ROUTES'],
        ['or nest them under a server-bound parameter:'],
        $unclassified->all(),
    )));
});

test('every panel route is named so the allowlist can match it', function () {
    $unnamed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('panel', $route->middleware(), true))
        ->filter(fn ($route) => $route->getName() === null)
        ->map(fn ($route) => $route->methods()[0].'  '.$route->uri())
        ->values();

    expect($unnamed->all())->toBe([], implode("\n", array_merge(
        ["EnforceServerScope's allowlist is keyed by route name, so an unnamed"],
        ['route can never be allowlisted. Give these a name:'],
        $unnamed->all(),
    )));
});

test('the serverless allowlist has no stale entries', function () {
    $allowlist = (new ReflectionClass(EnforceServerScope::class))->getConstant('SERVERLESS_ROUTES');

    $realNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->all();

    $stale = array_values(array_diff($allowlist, $realNames));

    expect($stale)->toBe([], "Allowlist names no longer matching any route:\n".implode("\n", $stale));
});

test('root-equivalent actions are administrator-only', function () {
    // servers.terminal is intentionally not here: members get terminal access
    // on assigned servers, restricted to the webapp user via the session
    // token (TerminalController::auth()), not the admin middleware.
    $mustBeAdmin = [
        'servers.create', 'servers.store', 'servers.provision',
        'servers.restart', 'servers.regenerate-token', 'servers.destroy',
        'system-users.index', 'system-users.store', 'system-users.sudo',
        'system-users.shell', 'system-users.destroy',
        'security.index', 'security.firewall.store', 'security.firewall.destroy',
        'security.fail2ban.install', 'security.fail2ban.ban', 'security.fail2ban.unban',
        'server.ssh-keys.deploy', 'server.ssh-keys.revoke',
    ];

    $missing = [];

    foreach ($mustBeAdmin as $name) {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            $missing[] = "{$name} (route does not exist)";

            continue;
        }

        if (! in_array('admin', $route->gatherMiddleware(), true)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], "Lost the 'admin' carve-out:\n".implode("\n", $missing));
});
