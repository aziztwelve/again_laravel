<?php

namespace App\Http\Controllers\Api\Public\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Client;
use App\Models\UserProfile;
use App\Services\Messaging\ConversationService;
use App\Services\WhatsApp\WhatsAppMediaStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    protected ConversationService $conversationService;

    protected WhatsAppMediaStore $mediaStore;

    public function __construct(ConversationService $conversationService, WhatsAppMediaStore $mediaStore)
    {
        $this->conversationService = $conversationService;
        $this->mediaStore = $mediaStore;
    }

    /**
     * Получить webhook от WhatsApp сервиса
     * POST /api/public/whatsapp/webhook
     */
    public function webhook(Request $request)
    {
        try {
            $data = $request->validate([
                'phone_number' => 'required|string',
                // У фото, голосовых, стикеров и файлов текста нет (приходит пустая строка):
                // такие сообщения не отбрасываем, а показываем оператору с пометкой.
                'message_text' => 'nullable|string',
                'message_id' => 'required|string',
                'timestamp' => 'required|string',
                'from_id' => 'required|string',
                // Реальный номер и имя профиля отправителя (WhatsApp отдаёт идентификатор
                // вида …@lid; номер сервис узнаёт отдельно и может не получить, если
                // человек его скрыл).
                'sender_phone' => 'nullable|string|max:32',
                'sender_name' => 'nullable|string|max:255',
                // Вложение (фото, голосовое, видео, стикер, файл): сервис WhatsApp присылает его
                // файлом в этом же запросе. media_type — тип сообщения WhatsApp (image, ptt, …);
                // media_error — вложение было, но скачать его не удалось.
                'media' => 'nullable|file|max:51200',
                'media_type' => 'nullable|string|max:32',
                'media_error' => 'nullable|string|max:120',
            ]);

            Log::info("WhatsAppWebhookController: Webhook received", [
                'data' => \Illuminate\Support\Arr::except($data, ['media']),
                'has_media' => $request->hasFile('media'),
            ]);

            return DB::transaction(function () use ($data, $request) {
                // external_id = phone_number
                $phoneNumber = $data['phone_number'];
                $messageText = trim((string) ($data['message_text'] ?? ''));

                // Вложение сохраняем до создания сообщения; при сбое записи сообщение всё равно
                // придёт, но с пометкой, а не потеряется.
                $attachments = [];
                $mediaFailed = ! empty($data['media_error']);
                if ($request->hasFile('media')) {
                    try {
                        $attachments[] = $this->mediaStore->store($request->file('media'), $data['media_type'] ?? null);
                    } catch (\Throwable $e) {
                        Log::error('WhatsAppWebhookController: failed to store media', ['error' => $e->getMessage()]);
                        $mediaFailed = true;
                    }
                }

                if ($messageText === '' && $attachments === []) {
                    $messageText = $mediaFailed
                        ? '[Не удалось получить вложение из WhatsApp. Откройте его в WhatsApp]'
                        : '[Сообщение без текста: фото, голосовое, стикер или файл. Откройте его в WhatsApp]';
                }

                // Ищем или создаём conversation
                $conversation = Conversation::firstOrCreate(
                    [
                        'source' => 'whatsapp',
                        'external_id' => $phoneNumber,
                    ],
                    [
                        'status' => 'active',
                        'last_message_at' => now(),
                        'unread_messages_count' => 0,
                    ]
                );

                // Добавляем входящее сообщение
                $messageData = [
                    'direction' => 'incoming',
                    'content' => $messageText,
                    'attachments' => $attachments,
                    'content_type' => 'text',
                    'status' => 'delivered',
                    'source_data' => [
                        'whatsapp_message_id' => $data['message_id'],
                        'whatsapp_phone_number' => $phoneNumber,
                        'whatsapp_from_id' => $data['from_id'],
                        'timestamp' => $data['timestamp'],
                    ]
                ];

                $this->applySenderInfo($conversation, $data['sender_phone'] ?? null, $data['sender_name'] ?? null);

                $this->conversationService->addMessage($conversation, $messageData);

                Log::info("WhatsAppWebhookController: Message saved", [
                    'conversation_id' => $conversation->id,
                    'message_id' => $data['message_id']
                ]);

                event(new \App\Events\ConversationUpdated($conversation));

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook processed successfully'
                ], 200);

            });

        } catch (\Exception $e) {
            Log::error("WhatsAppWebhookController: Exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Сохранить имя/номер собеседника и привязать диалог к клиенту по номеру телефона.
     * Только для новых данных: уже привязанного клиента не перезаписываем.
     */
    private function applySenderInfo(Conversation $conversation, ?string $phone, ?string $name): void
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $name = trim((string) $name);

        $updates = [];
        if ($digits !== '' && strlen($digits) >= 7 && $conversation->contact_phone !== $digits) {
            $updates['contact_phone'] = $digits;
        }
        if ($name !== '' && $conversation->contact_name !== $name) {
            $updates['contact_name'] = mb_substr($name, 0, 255);
        }
        if ($conversation->client_id === null && $digits !== '') {
            $clientId = $this->findClientIdByPhone($digits);
            if ($clientId !== null) {
                $updates['client_id'] = $clientId;
            }
        }

        if ($updates !== []) {
            $conversation->update($updates);
        }
    }

    /**
     * Клиент с таким телефоном (по последним 10 цифрам, чтобы 8/7/+7 совпадали).
     * Если подходит несколько разных клиентов — не угадываем и не привязываем.
     */
    private function findClientIdByPhone(string $digits): ?int
    {
        if (strlen($digits) < 10) {
            return null;
        }

        $ids = UserProfile::query()
            ->whereNotNull('client_id')
            ->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10) = ?", [substr($digits, -10)])
            ->pluck('client_id')
            ->unique()
            ->filter(fn ($id) => Client::whereKey($id)->exists())
            ->values();

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
