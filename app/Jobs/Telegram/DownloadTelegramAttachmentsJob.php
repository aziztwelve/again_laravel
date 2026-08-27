<?php

namespace App\Jobs\Telegram;

use App\Events\MessageCreated;
use App\Models\Message;
use App\Services\Telegram\TelegramService;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Догружает вложения входящего сообщения Telegram.
 *
 * Внутри webhook-запроса это делать нельзя: `getFile` и сам файл идут через
 * SOCKS5-прокси, каждый запрос — до 20 секунд, и на крупном файле ответ не
 * успевает уложиться в таймаут Telegram. Telegram считает доставку неудачной
 * (`last_error_message: Connection timed out`) и повторяет апдейт, из-за чего
 * в диалоге появляются дубли.
 *
 * Сообщение создаётся сразу (текст виден оператору мгновенно), вложения
 * прикрепляются этой задачей, после чего MessageCreated транслируется
 * повторно — дашборд находит сообщение по id и обновляет его, не дублируя.
 *
 * Токен бота в payload не кладём: он хранится в telegraph_bots, а payload
 * задачи лежит в таблице `jobs` и в `failed_jobs`.
 */
class DownloadTelegramAttachmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    /**
     * @param  array<int, array{file_id: string, file_name: string|null}>  $descriptors
     */
    public function __construct(
        public int $messageId,
        public array $descriptors,
        public ?int $telegraphBotId = null,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('tg-attachments:'.$this->messageId))->expireAfter(900)];
    }

    public function handle(TelegramService $telegramService): void
    {
        $message = Message::query()->find($this->messageId);

        if (! $message) {
            Log::warning('DownloadTelegramAttachmentsJob: сообщение не найдено', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $botToken = $this->resolveBotToken();

        if (! $botToken) {
            Log::error('DownloadTelegramAttachmentsJob: не найден токен бота', [
                'message_id' => $this->messageId,
                'telegraph_bot_id' => $this->telegraphBotId,
            ]);

            return;
        }

        $attached = 0;

        foreach ($this->descriptors as $descriptor) {
            $fileId = $descriptor['file_id'] ?? null;

            if (! $fileId) {
                continue;
            }

            // Повторный прогон задачи не должен создавать дубли вложений.
            if ($message->attachments()->where('source_file_id', $fileId)->exists()) {
                continue;
            }

            $file = $telegramService->downloadTelegramFile(
                $fileId,
                $descriptor['file_name'] ?? null,
                $botToken
            );

            if (! $file) {
                Log::warning('DownloadTelegramAttachmentsJob: файл не скачался', [
                    'message_id' => $message->id,
                    'file_id' => $fileId,
                ]);

                continue;
            }

            $message->attachments()->create($file + ['source_file_id' => $fileId]);
            $attached++;
        }

        if ($attached === 0) {
            return;
        }

        try {
            event(new MessageCreated($message->fresh()));
        } catch (\Throwable $e) {
            Log::warning('DownloadTelegramAttachmentsJob: вложения сохранены, но broadcast не ушёл', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveBotToken(): ?string
    {
        if ($this->telegraphBotId) {
            return TelegraphBot::query()->whereKey($this->telegraphBotId)->value('token');
        }

        // Фолбэк для окружений с единственным ботом.
        return TelegraphBot::query()->latest('id')->value('token');
    }
}
