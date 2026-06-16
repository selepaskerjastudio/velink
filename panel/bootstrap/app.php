<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyGatewaySecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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

            Route::middleware('web')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'install/provision',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
