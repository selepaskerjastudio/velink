<?php

namespace App\Providers;

use App\Events\ServerAlertResolved;
use App\Events\ServerAlertTriggered;
use App\Listeners\SendAlertNotifications;
use App\Services\Edge\CaddyEdgeProxy;
use App\Services\Edge\EdgeProxy;
use App\Services\Edge\NullEdgeProxy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the edge proxy implementation from config. `none` (default)
        // disables the feature entirely via a no-op driver.
        $this->app->bind(EdgeProxy::class, function () {
            return match (config('velink.edge_proxy.driver')) {
                'caddy' => new CaddyEdgeProxy(
                    config('velink.edge_proxy.admin_url'),
                    config('velink.edge_proxy.server', 'edge'),
                ),
                default => new NullEdgeProxy,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ServerAlertTriggered::class, [SendAlertNotifications::class, 'handleTriggered']);
        Event::listen(ServerAlertResolved::class, [SendAlertNotifications::class, 'handleResolved']);
    }
}
