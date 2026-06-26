<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Sends messages to a Discord channel via an incoming webhook URL.
 * Laravel has no built-in Discord notification channel, so this is a
 * thin Http::post wrapper.
 */
class DiscordWebhookService
{
    public function send(string $webhookUrl, string $message): bool
    {
        $response = Http::timeout(15)->post($webhookUrl, [
            'content' => $message,
        ]);

        return $response->successful();
    }
}
