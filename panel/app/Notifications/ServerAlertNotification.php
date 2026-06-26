<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use App\Models\ServerAlert;
use App\Services\DiscordWebhookService;
use App\Services\TelegramService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

/**
 * Alert notification delivered to a specific NotificationChannel.
 *
 * The $channel determines the delivery method — the notifiable is a
 * NotificationChannel (not a User) so each channel routes independently.
 */
class ServerAlertNotification extends Notification
{
    public function __construct(
        private ServerAlert $alert,
        private bool $resolved = false,
    ) {}

    /**
     * @param  NotificationChannel  $channel  The channel to deliver via.
     */
    public function via(object $channel): array
    {
        return match ($channel->type) {
            'email' => ['mail'],
            'slack' => ['slack'],
            'discord' => ['discord'],
            'telegram' => ['telegram'],
            default => [],
        };
    }

    public function toMail(object $channel): MailMessage
    {
        $server = $this->alert->server;
        $status = $this->resolved ? '✅ Resolved' : '🔴 Alert';

        return (new MailMessage)
            ->subject("[Velink] {$status}: {$this->metricLabel()} on {$server->name}")
            ->line("Server: **{$server->name}** ({$server->public_ip})")
            ->line("Metric: **{$this->metricLabel()}**")
            ->line("Current: **{$this->alert->value}%** (threshold: {$this->alert->threshold}%)")
            ->line($this->resolved ? 'This alert has been resolved — the metric is back to normal.' : $this->alert->message)
            ->action('View Server', url("/servers/{$server->uuid}"));
    }

    public function toSlack(object $channel): SlackMessage
    {
        $server = $this->alert->server;
        $emoji = $this->resolved ? ':white_check_mark:' : ':rotating_light:';

        return (new SlackMessage)
            ->from('Velink', ':fire:')
            ->to($channel->config['webhook_url'] ?? null)
            ->content("{$emoji} *{$this->metricLabel()}* on *{$server->name}* — {$this->alert->value}% (threshold {$this->alert->threshold}%)");
    }

    /**
     * Custom Discord delivery — called by the channel's send method.
     */
    public function toDiscord(object $channel): string
    {
        $server = $this->alert->server;
        $emoji = $this->resolved ? '✅' : '🔴';

        $message = "{$emoji} **{$this->metricLabel()}** on **{$server->name}** — {$this->alert->value}% (threshold {$this->alert->threshold}%)";
        $message .= "\nServer: {$server->public_ip}";

        if ($channel->config['webhook_url'] ?? null) {
            app(DiscordWebhookService::class)->send($channel->config['webhook_url'], $message);
        }

        return $message;
    }

    /**
     * Custom Telegram delivery — called by the channel's send method.
     */
    public function toTelegram(object $channel): string
    {
        $server = $this->alert->server;
        $emoji = $this->resolved ? '✅' : '🔴';

        $message = "{$emoji} *{$this->metricLabel()}* on *{$server->name}* — {$this->alert->value}% (threshold {$this->alert->threshold}%)";
        $message .= "\nServer: {$server->public_ip}";

        $botToken = $channel->config['bot_token'] ?? null;
        $chatId = $channel->config['chat_id'] ?? null;

        if ($botToken && $chatId) {
            app(TelegramService::class)->send($botToken, $chatId, $message);
        }

        return $message;
    }

    private function metricLabel(): string
    {
        return ucfirst($this->alert->metric_type);
    }
}
