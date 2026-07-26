<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\BaseNotificationChannel;
use App\Services\Integrations\AmneziaVpnService;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Log;

class TelegramNotificationChannel extends BaseNotificationChannel
{
    public function send(string $recipientId, string $message, array $data = []): bool
    {
        try {
            // Чат хранит ссылку на конкретного бота. Facade без bot/chat
            // не может определить токен и даёт "No TelegraphBot defined".
            $chat = TelegraphChat::query()
                ->where('chat_id', $recipientId)
                ->whereHas('bot')
                ->orderByDesc('telegraph_bot_id')
                ->first();

            if (! $chat) {
                Log::warning('TelegramNotificationChannel: Chat or bot not found', [
                    'recipient_id' => $recipientId,
                ]);

                return false;
            }

            // Telegraph creates its own HTTP request and cannot receive the
            // dynamic SOCKS5 options. Send through the shared Amnezia client.
            $telegram = app(AmneziaVpnService::class)->telegramHttp();
            $imageUrl = $data['image_url'] ?? null;

            if ($imageUrl) {
                $response = $telegram->post("https://api.telegram.org/bot{$chat->bot->token}/sendPhoto", [
                    'chat_id' => $recipientId,
                    'photo' => $imageUrl,
                    'caption' => $message,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[[
                            'text' => 'Купить',
                            'url' => $data['product_url'] ?? $imageUrl,
                        ]]],
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $response = $telegram->post("https://api.telegram.org/bot{$chat->bot->token}/sendMessage", [
                    'chat_id' => $recipientId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ]);
            }

            if (! $response->successful()) {
                Log::warning('TelegramNotificationChannel: Telegram rejected message', [
                    'recipient_id' => $recipientId,
                    'response' => $response->json(),
                ]);

                return false;
            }

            $this->logSend($recipientId, $this->getChannelName(), $message, true);

            return true;

        } catch (\Exception $e) {
            $this->handleError($this->getChannelName(), $e);
            $this->logSend($recipientId, $this->getChannelName(), $message, false);

            return false;
        }
    }

    public function getChannelName(): string
    {
        return 'telegram';
    }
}
