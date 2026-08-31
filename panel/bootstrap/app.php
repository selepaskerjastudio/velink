<?php

use App\Http\Middleware\EnforceServerScope;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyGatewaySecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::prefix('internal')
                ->middleware(VerifyGatewaySecret::class)
                ->group(base_path('routes/internal.php'));

            // Edge-proxy (Caddy) on-demand TLS gate. No gateway secret — Caddy's
            // `ask` is a plain GET; it is gated by its own static query secret.
            Route::prefix('internal')
                ->group(base_path('routes/edge.php'));

            Route::middleware('web')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Every authenticated browser route uses this group instead of bare
        // 'auth', so a route added to an existing file inherits access scoping
        // by default rather than opting into it.
        $middleware->group('panel', [
            'auth',
            EnsureUserIsActive::class,
            EnforceServerScope::class,
        ]);

        // EnforceServerScope inspects bound route models, so it must run after
        // SubstituteBindings. Route middleware already sits after the web group
        // (where SubstituteBindings lives) and the sorter only ever hoists
        // priority-mapped middleware upward, so the natural order is correct —
        // these entries pin it against future reordering.
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->appendToPriorityList(EnsureUserIsActive::class, EnforceServerScope::class);
        $middleware->appendToPriorityList(EnforceServerScope::class, EnsureUserIsAdmin::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'install/provision',
        ]);
    })
    ->withEvents()
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
