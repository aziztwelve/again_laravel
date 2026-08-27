<?php

namespace App\Services\Telegram;

use App\Models\UserProfile;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\File\FileStorageService;
use App\Services\Integrations\AmneziaVpnService;
use App\Jobs\Telegram\DownloadTelegramAttachmentsJob;
use App\Services\Messaging\ConversationService;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    private ConversationService $conversationService;
    private TelegramCommandHandler $commandHandler;
    private FileStorageService $fileStorage;

    public function __construct(
        ConversationService    $conversationService,
        TelegramCommandHandler $commandHandler,
        FileStorageService     $fileStorage
    )
    {
        $this->conversationService = $conversationService;
        $this->commandHandler = $commandHandler;
        $this->fileStorage = $fileStorage;
    }

    /**
     * @param int $telegramUserId
     * @param UserProfile|null $client_profile
     * @param string $content
     * @param array $requestData - raw данные из webhook request
     * @param string|null $botToken - токен бота
     * @return void
     */
    public function findOrCreateConversationAndSendMessage(
        int              $telegramUserId,
        UserProfile|null $client_profile,
        string           $content,
        array            $requestData = [],
        ?string          $botToken = null,
        ?int             $orderId = null
    )
    {
        $conversation = null;

        if ($client_profile) {
            $conversation = Conversation::where('client_id', $client_profile->client_id)
                ->where('source', 'telegram')
                ->first();
        }

        if (!$conversation) {
            $conversation = Conversation::where('external_id', $telegramUserId)
                ->where('source', 'telegram')
                ->first();
        }

        if (!$conversation) {
            $conversation = $this->conversationService->createConversation(
                'telegram',
                $telegramUserId,
                $client_profile->client_id ?? null
            );
        }

        // Вложения не качаем внутри webhook-запроса: getFile + сам файл идут
        // через SOCKS5-прокси и легко выходят за таймаут Telegram, после чего
        // Telegram считает доставку неуспешной и повторяет апдейт. Из request
        // достаём только дескрипторы (это без сети), файлы забирает очередь.
        $descriptors = $requestData ? $this->extractAttachmentDescriptors($requestData) : [];

        $messageData = [
            'conversation_id' => $conversation->id,
            'content' => $content,
            'direction' => 'incoming',
            'status' => 'sent',
            'content_type' => 'text',
            'source_data' => $orderId ? ['order_id' => $orderId] : null,
            'attachments' => [],
        ];

        $message = $this->conversationService->addMessage($conversation, $messageData);

        if ($descriptors) {
            DownloadTelegramAttachmentsJob::dispatch($message->id, $descriptors, $botToken ? $this->resolveBotId($botToken) : null);
        }
    }

    /**
     * Дескрипторы вложений из webhook-апдейта: file_id, имя и тип, без
     * обращений к Telegram API. Скачиванием занимается
     * DownloadTelegramAttachmentsJob.
     *
     * @return array<int, array{file_id: string, file_name: string|null}>
     */
    public function extractAttachmentDescriptors(array $messageData): array
    {
        $descriptors = [];

        // Фото
        if (isset($messageData['photo']) && is_array($messageData['photo'])) {
            foreach ($this->extractPhotos($messageData['photo']) as $photo) {
                $descriptors[] = ['file_id' => $photo['file_id'], 'file_name' => null];
            }
        }

        // Аудио, голосовые, документы
        foreach ([
            ['audio', fn (array $data) => $this->extractAudio($data)],
            ['voice', fn (array $data) => $this->extractVoice($data)],
            ['document', fn (array $data) => $this->extractDocument($data)],
        ] as [$key, $extractor]) {
            if (! isset($messageData[$key]) || ! is_array($messageData[$key])) {
                continue;
            }

            $extracted = $extractor($messageData[$key]);

            if ($extracted) {
                $descriptors[] = [
                    'file_id' => $extracted['file_id'],
                    'file_name' => $extracted['file_name'] ?? null,
                ];
            }
        }

        return $descriptors;
    }

    private function resolveBotId(string $botToken): ?int
    {
        return TelegraphBot::query()->where('token', $botToken)->value('id');
    }

    private function extractPhotos(array $photoArray): array
    {
        $photos = [];
        $largestPhoto = end($photoArray);

        if (isset($largestPhoto['file_id'])) {
            $photos[] = [
                'file_id' => $largestPhoto['file_id'],
                'file_size' => $largestPhoto['file_size'] ?? null,
                'type' => 'photo'
            ];
        }

        return $photos;
    }

    private function extractAudio(array $audioData): ?array
    {
        if (isset($audioData['file_id'])) {
            return [
                'file_id' => $audioData['file_id'],
                'file_size' => $audioData['file_size'] ?? null,
                'duration' => $audioData['duration'] ?? null,
                'file_name' => $audioData['file_name'] ?? 'audio_' . time() . '.mp3',
                'mime_type' => $audioData['mime_type'] ?? 'audio/mpeg',
                'type' => 'audio'
            ];
        }

        return null;
    }

    private function extractVoice(array $voiceData): ?array
    {
        if (isset($voiceData['file_id'])) {
            return [
                'file_id' => $voiceData['file_id'],
                'file_size' => $voiceData['file_size'] ?? null,
                'duration' => $voiceData['duration'] ?? null,
                'file_name' => 'voice_' . time() . '.ogg',
                'mime_type' => $voiceData['mime_type'] ?? 'audio/ogg',
                'type' => 'voice'
            ];
        }

        return null;
    }

    private function extractDocument(array $documentData): ?array
    {
        if (isset($documentData['file_id'])) {
            return [
                'file_id' => $documentData['file_id'],
                'file_size' => $documentData['file_size'] ?? null,
                'file_name' => $documentData['file_name'] ?? 'document_' . time(),
                'mime_type' => $documentData['mime_type'] ?? 'application/octet-stream',
                'type' => 'document'
            ];
        }

        return null;
    }

    /**
     * Скачивает файл из Telegram и складывает в public-диск.
     * Публичный, потому что вызывается из DownloadTelegramAttachmentsJob:
     * внутри webhook-запроса это делать нельзя (таймаут Telegram).
     */
    public function downloadTelegramFile(string $fileId, ?string $fileName = null, ?string $botToken = null): ?array
    {
        try {
            if (!$botToken) {
                Log::error('Bot token not provided');
                return null;
            }

            Log::info('Downloading Telegram file', [
                'file_id' => $fileId,
                'token_prefix' => substr($botToken, 0, 10) . '...'
            ]);

            $telegramHttp = app(AmneziaVpnService::class)->telegramHttp();

            // 1. Получаем информацию о файле
            $fileInfoResponse = $telegramHttp->get("https://api.telegram.org/bot{$botToken}/getFile", [
                'file_id' => $fileId
            ]);

            if (!$fileInfoResponse->successful()) {
                Log::error('Failed to get Telegram file info', [
                    'file_id' => $fileId,
                    'status' => $fileInfoResponse->status(),
                    'response' => $fileInfoResponse->body()
                ]);
                return null;
            }

            $fileInfo = $fileInfoResponse->json();
            $filePath = $fileInfo['result']['file_path'] ?? null;

            if (!$filePath) {
                Log::error('File path not found in Telegram response', [
                    'file_id' => $fileId,
                    'response' => $fileInfo
                ]);
                return null;
            }

            Log::info('Got file path from Telegram', ['file_path' => $filePath]);

            // 2. Скачиваем файл
            $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
            $fileResponse = $telegramHttp->get($fileUrl);

            if (!$fileResponse->successful()) {
                Log::error('Failed to download Telegram file', [
                    'file_id' => $fileId,
                    'url' => $fileUrl,
                    'status' => $fileResponse->status()
                ]);
                return null;
            }

            $fileContent = $fileResponse->body();

            if (!$fileContent) {
                return null;
            }

            Log::info('File downloaded successfully', ['size' => strlen($fileContent)]);

            // 3. Определяем расширение и MIME-тип
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (!$extension) {
                $extension = $this->guessExtensionFromFileName($fileName);
            }

            $mimeType = $this->guessMimeType($extension);

            // 4. Сохраняем файл
            $hash = md5(time() . uniqid());
            $storedFileName = $hash . '.' . $extension;
            $directory = 'chat-attachments/' . now()->format('Y/m');
            $fullPath = $directory . '/' . $storedFileName;

            Storage::disk('public')->put($fullPath, $fileContent);

            Log::info('File saved to storage', ['path' => $fullPath]);

            // 5. Формируем данные для БД
            return [
                'type' => $this->getAttachmentType($mimeType),
                'url' => url('storage/' . $fullPath),
                'file_path' => $fullPath,
                'file_name' => $fileName ?: $storedFileName,
                'file_size' => strlen($fileContent),
                'mime_type' => $mimeType,
            ];

        } catch (\Exception $e) {
            Log::error('Error downloading Telegram file', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function guessExtensionFromFileName(?string $fileName): string
    {
        if ($fileName && str_contains($fileName, '.')) {
            return pathinfo($fileName, PATHINFO_EXTENSION);
        }
        return 'bin';
    }

    private function guessMimeType(string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    private function getAttachmentType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        return 'file';
    }

    public function syncUserProfile(
        int   $telegramUserId,
        array $userInfo,
        int   $chatId
    ): UserProfile
    {
        $userProfile = UserProfile::where('telegram_user_id', $telegramUserId)->first();

        if (!$userProfile) {
            throw new \Exception('User profile not found');
        }

        $userProfile->update([
            'first_name' => $userInfo['first_name'] ?? null,
            'last_name' => $userInfo['last_name'] ?? null,
            'telegram_chat_id' => $chatId,
        ]);

        return $userProfile;
    }
}
