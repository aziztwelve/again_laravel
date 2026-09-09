<?php

namespace App\Console\Commands;

use App\Models\UserProfile;
use App\Services\Integrations\AmneziaVpnService;
use App\Services\Messaging\ChatBindingService;
use App\Services\Telegram\TelegramService;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Резервный приём Telegram-сообщений для серверов, до которых Telegram не
 * может доставить webhook. Telegram API опрашивается через настроенный VPN.
 */
class PollTelegramIncomingMessages extends Command
{
    protected $signature = 'telegram:poll-incoming {--once : Выполнить один запрос getUpdates}';

    protected $description = 'Получить входящие сообщения Telegram через Bot API';

    public function __construct(
        private readonly TelegramService $telegramService,
        private readonly ChatBindingService $chatBindingService,
        private readonly AmneziaVpnService $vpn,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach (TelegraphBot::query()->get() as $bot) {
            if (! $this->pollBot($bot)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function pollBot(TelegraphBot $bot): bool
    {
        $cacheKey = 'telegram:poll-offset:'.$bot->id;
        $offset = Cache::get($cacheKey);

        try {
            $response = $this->vpn->telegramHttp()->get(
                "https://api.telegram.org/bot{$bot->token}/getUpdates",
                array_filter([
                    'offset' => is_numeric($offset) ? (int) $offset : null,
                    'limit' => 100,
                    'timeout' => 0,
                    'allowed_updates' => json_encode(['message'], JSON_THROW_ON_ERROR),
                ], static fn ($value) => $value !== null),
            );
        } catch (\Throwable $exception) {
            Log::error('Telegram polling failed', ['bot_id' => $bot->id, 'error' => $exception->getMessage()]);
            $this->error("{$bot->name}: {$exception->getMessage()}");

            return false;
        }

        if (! $response->ok() || ! $response->json('ok')) {
            Log::error('Telegram polling API error', ['bot_id' => $bot->id, 'response' => $response->body()]);
            $this->error("{$bot->name}: getUpdates returned {$response->status()}");

            return false;
        }

        foreach ($response->json('result', []) as $update) {
            $updateId = $update['update_id'] ?? null;
            $message = $update['message'] ?? null;

            if (is_array($message) && isset($message['from']['id'])) {
                $telegramId = (int) $message['from']['id'];
                $text = (string) ($message['text'] ?? $message['caption'] ?? '[Вложение]');

                if (preg_match('/^\/start(?:\s+(.+))?$/u', $text, $matches)) {
                    $this->chatBindingService->resolveBinding($matches[1] ?? null, 'telegram', (string) $telegramId);
                }

                $profile = UserProfile::query()->where('telegram_user_id', $telegramId)->first();
                $this->telegramService->findOrCreateConversationAndSendMessage(
                    $telegramId,
                    $profile,
                    $text,
                    $message,
                    $bot->token,
                    $this->chatBindingService->resolveBoundOrderId('telegram', (string) $telegramId),
                );
            }

            if (is_numeric($updateId)) {
                Cache::forever($cacheKey, (int) $updateId + 1);
            }
        }

        return true;
    }
}
