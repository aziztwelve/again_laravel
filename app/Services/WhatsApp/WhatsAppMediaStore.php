<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Сохраняет вложения входящих сообщений WhatsApp (фото, голосовые, видео,
 * стикеры, файлы) так же, как другие каналы: в chat-attachments/ГГГГ/ММ на диске
 * public, с данными для таблицы message_attachments.
 *
 * Тип вложения (image / video / audio / file) определяется по MIME содержимого,
 * а не по расширению из имени: WhatsApp часто присылает файл без имени, а
 * голосовые сообщения приходят как «audio/ogg; codecs=opus».
 */
class WhatsAppMediaStore
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'audio/opus' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/webm' => 'webm', 'audio/wav' => 'wav',
        'application/pdf' => 'pdf', 'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];

    /** Имя по умолчанию, когда WhatsApp не прислал имя файла. */
    private const DEFAULT_NAMES = ['image' => 'Фото', 'video' => 'Видео', 'audio' => 'Голосовое сообщение', 'file' => 'Файл'];

    /**
     * @param  string|null  $mediaType  тип сообщения WhatsApp: image, video, audio, ptt, document, sticker…
     * @return array{type:string,url:string,file_path:string,file_name:string,file_size:int,mime_type:string}
     */
    public function store(UploadedFile $file, ?string $mediaType = null): array
    {
        $mime = $this->normalizeMime((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $type = $this->attachmentType($mime);
        $extension = self::EXTENSIONS[$mime] ?? $this->extensionFromName($file->getClientOriginalName()) ?? 'bin';

        $directory = 'chat-attachments/'.now()->format('Y/m');
        $storedName = md5(time().uniqid()).'.'.$extension;
        $path = $directory.'/'.$storedName;

        // putFileAs не бросает исключение при сбое записи (диск с throw=false):
        // без проверки в чате осталась бы ссылка на несуществующий файл.
        if (Storage::disk('public')->putFileAs($directory, $file, $storedName) === false) {
            throw new RuntimeException('Не удалось сохранить вложение WhatsApp');
        }

        return [
            'type' => $type,
            'url' => url('storage/'.$path),
            'file_path' => $path,
            'file_name' => $this->displayName($file->getClientOriginalName(), $type, $extension, $mediaType),
            'file_size' => (int) $file->getSize(),
            'mime_type' => $mime,
        ];
    }

    /** «audio/ogg; codecs=opus» → «audio/ogg». */
    public function normalizeMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    public function attachmentType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function extensionFromName(?string $name): ?string
    {
        $extension = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) ? $extension : null;
    }

    private function displayName(?string $original, string $type, string $extension, ?string $mediaType): string
    {
        $original = trim((string) $original);
        // «attachment» — имя-заглушка, которое сервис WhatsApp подставляет, когда файл пришёл без имени.
        if ($original !== '' && ! preg_match('/^(attachment|php[A-Za-z0-9]+|[0-9a-f]{32})(\.\w+)?$/', $original)) {
            return mb_substr($original, 0, 200);
        }

        $label = match ($mediaType) {
            'sticker' => 'Стикер',
            'ptt' => 'Голосовое сообщение',
            default => self::DEFAULT_NAMES[$type],
        };

        return $label.'.'.$extension;
    }
}
