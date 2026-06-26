<?php

namespace App\Providers;

use App\Events\ServerAlertResolved;
use App\Events\ServerAlertTriggered;
use App\Listeners\SendAlertNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
