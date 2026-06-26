<?php

namespace App\Listeners;

use App\Events\ServerAlertResolved;
use App\Events\ServerAlertTriggered;
use App\Models\NotificationChannel;
use App\Notifications\ServerAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends alert notifications to all enabled global notification channels.
 *
 * Queued so the metrics handler (~30s cycle) never blocks on SMTP/HTTP.
 */
class SendAlertNotifications implements ShouldQueue
{
    public function handleTriggered(ServerAlertTriggered $event): void
    {
        $this->send($event->alert, resolved: false);
    }

    public function handleResolved(ServerAlertResolved $event): void
    {
        $this->send($event->alert, resolved: true);
    }

    private function send($alert, bool $resolved): void
    {
        // Global scope: all enabled channels from all users.
        $channels = NotificationChannel::where('enabled', true)->get();

        if ($channels->isEmpty()) {
            return;
        }

        foreach ($channels as $channel) {
            try {
                Notification::send($channel, new ServerAlertNotification($alert, $resolved));
            } catch (\Throwable $e) {
                Log::warning("Failed to send notification to {$channel->type} channel {$channel->uuid}: {$e->getMessage()}");
            }
        }
    }
}
