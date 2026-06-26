<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Sends messages via the Telegram Bot API.
 * Laravel has no built-in Telegram notification channel, so this is a
 * thin Http::post wrapper.
 */
class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function send(string $botToken, string $chatId, string $message): bool
    {
        $response = Http::timeout(15)->post(self::API_BASE.$botToken.'/sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        return $response->successful();
    }
}
