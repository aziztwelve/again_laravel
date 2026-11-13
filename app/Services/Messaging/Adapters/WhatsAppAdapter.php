<?php

namespace App\Services\Messaging\Adapters;

use App\Services\Messaging\AbstractMessageAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppAdapter extends AbstractMessageAdapter
{
    protected string $whatsappServiceUrl;

    public function __construct()
    {
        // config(), а не env(): при закэшированном конфиге env() в рантайме
        // возвращает null. Адрес сервиса — внутренний, от домена не зависит.
        $this->whatsappServiceUrl = rtrim((string) config('services.whatsapp.service_url'), '/');
    }

    /**
     * Подпись к вложению в WhatsApp не длиннее этого; более длинный текст уходит отдельным сообщением.
     */
    private const CAPTION_LIMIT = 1000;

    /**
     * Отправить сообщение в WhatsApp
     * @param string $externalId - идентификатор чата (номер телефона или …@lid)
     * @param string|null $content - текст сообщения
     * @param array $attachments - вложения (type, file_path, file_name, mime_type)
     * @return bool
     */
    public function sendMessage(string $externalId, ?string $content, array $attachments = []): bool
    {
        $content = trim((string) $content);

        try {
            if ($attachments === []) {
                return $this->sendText($externalId, $content);
            }

            // Подпись — у первого вложения; длинный текст идёт отдельным сообщением перед ними.
            $caption = $content;
            if (mb_strlen($caption) > self::CAPTION_LIMIT) {
                if (! $this->sendText($externalId, $caption)) {
                    return false;
                }
                $caption = '';
            }

            foreach (array_values($attachments) as $index => $attachment) {
                if (! $this->sendMedia($externalId, $attachment, $index === 0 ? $caption : '')) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error("WhatsAppAdapter: Exception while sending message", [
                'phone_number' => $externalId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function sendText(string $externalId, string $content): bool
    {
        if ($content === '') {
            Log::error('WhatsAppAdapter: nothing to send (empty text and no attachments)', ['phone_number' => $externalId]);

            return false;
        }

        return $this->post('/send-message', ['phone_number' => $externalId, 'message_text' => $content], $externalId);
    }

    /**
     * Отправить файл: сервис WhatsApp принимает его в base64 и шлёт как фото / видео / аудио
     * (документы уходят файлом).
     */
    private function sendMedia(string $externalId, array $attachment, string $caption): bool
    {
        $path = $attachment['file_path'] ?? null;
        $disk = Storage::disk('public');
        if (! $path || ! $disk->exists($path)) {
            Log::error('WhatsAppAdapter: attachment file not found', ['file_path' => $path]);

            return false;
        }

        $name = $attachment['file_name'] ?: basename($path);

        return $this->post('/send-media', [
            'phone_number' => $externalId,
            'data' => base64_encode($disk->get($path)),
            'mimetype' => $attachment['mime_type'] ?: ($disk->mimeType($path) ?: 'application/octet-stream'),
            'filename' => $name,
            'caption' => $caption !== '' ? $caption : null,
            'as_document' => ($attachment['type'] ?? 'file') === 'file',
        ], $externalId, 180);
    }

    private function post(string $endpoint, array $payload, string $externalId, int $timeout = 30): bool
    {
        $response = Http::timeout($timeout)->post($this->whatsappServiceUrl.$endpoint, $payload);

        if (! $response->successful()) {
            Log::error("WhatsAppAdapter: HTTP request failed", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return false;
        }

        $data = $response->json();

        if (isset($data['error'])) {
            Log::error("WhatsAppAdapter: Failed to send message", [
                'endpoint' => $endpoint,
                'phone_number' => $externalId,
                'error' => $data['error'] ?? 'Unknown error'
            ]);
            return false;
        }

        Log::info("WhatsAppAdapter: Message sent successfully", [
            'endpoint' => $endpoint,
            'phone_number' => $externalId
        ]);

        return true;
    }

    /**
     * Подключён ли WhatsApp (сервис отвечает и сессия активна). Любая ошибка = не подключён.
     */
    public function isConnected(): bool
    {
        try {
            $response = Http::timeout(3)->acceptJson()->get("{$this->whatsappServiceUrl}/status");

            return $response->successful() && (bool) $response->json('ready');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Отметить сообщение как прочитанное
     */
    public function markAsRead(string $externalId): bool
    {
        // WhatsApp Web не имеет встроенного API для отметки как прочитано
        return true;
    }

    public function getSourceName(): string
    {
        return 'whatsapp';
    }
}
