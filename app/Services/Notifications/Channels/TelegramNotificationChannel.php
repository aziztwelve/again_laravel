<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\BaseNotificationChannel;
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

            $response = $chat
                ->html(e($message))
                ->send();

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
