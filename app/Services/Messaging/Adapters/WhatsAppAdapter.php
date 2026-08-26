<?php

namespace App\Services\Messaging\Adapters;

use App\Services\Messaging\AbstractMessageAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Отправить сообщение в WhatsApp
     * @param string $externalId - номер телефона (+79991234567)
     * @param string $content - текст сообщения
     * @param array $attachments - вложения
     * @return bool
     */
    public function sendMessage(string $externalId, string $content, array $attachments = []): bool
    {
        try {
            $response = Http::post("{$this->whatsappServiceUrl}/send-message", [
                'phone_number' => $externalId,
                'message_text' => $content,
            ]);

            if (!$response->successful()) {
                Log::error("WhatsAppAdapter: HTTP request failed", [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return false;
            }

            $data = $response->json();

            if (isset($data['error'])) {
                Log::error("WhatsAppAdapter: Failed to send message", [
                    'phone_number' => $externalId,
                    'error' => $data['error'] ?? 'Unknown error'
                ]);
                return false;
            }

            Log::info("WhatsAppAdapter: Message sent successfully", [
                'phone_number' => $externalId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("WhatsAppAdapter: Exception while sending message", [
                'phone_number' => $externalId,
                'error' => $e->getMessage()
            ]);
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
