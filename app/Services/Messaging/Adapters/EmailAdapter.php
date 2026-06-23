<?php

namespace App\Services\Messaging\Adapters;

use App\Models\MailSetting;
use App\Services\Messaging\AbstractMessageAdapter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class EmailAdapter extends AbstractMessageAdapter
{
    protected ?MailSetting $settings = null;

    /**
     * Лениво загружает настройки почты. Бросает понятное исключение, только
     * если настроек нет И они реально нужны (при отправке письма).
     *
     * Раньше загрузка/проверка были в конструкторе, из-за чего адаптер падал
     * с TypeError при любом резолве (включая boot консольных команд и тесты
     * с RefreshDatabase, когда в БД ещё нет mail_settings).
     */
    protected function settings(): MailSetting
    {
        if ($this->settings === null) {
            $this->settings = MailSetting::first();

            if (!$this->settings) {
                Log::error("EmailAdapter: MailSettings not found in database");
                throw new Exception("Email settings не найдены в БД");
            }
        }

        return $this->settings;
    }

    /**
     * Отправить сообщение по email с вложениями
     * @param string $externalId - email адрес получателя
     * @param ?string $content - текст сообщения
     * @param array $attachments - вложения
     * @return bool
     */
    public function sendMessage(string $externalId, ?string $content, array $attachments = []): bool
    {
        try {
            $to = $externalId;

            // ← ОБНОВИЛИ: добавили отображение вложений в HTML
            $htmlContent = $this->buildHtmlContent($content, $attachments);

            Mail::html($htmlContent, function ($message) use ($to, $attachments) {
                $message->to($to)
                    ->from($this->settings()->from_address)
                    ->subject('Re: Ответ от поддержки');

                // ← ДОБАВИЛИ: Прикрепляем файлы
                $this->attachFiles($message, $attachments);
            });

            Log::info("EmailAdapter: Message sent successfully", [
                'to' => $to,
                'attachments_count' => count($attachments)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error("EmailAdapter: Exception while sending message", [
                'to' => $externalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Построить HTML контент письма с вложениями
     */
    private function buildHtmlContent(?string $content, array $attachments): string
    {
        $textContent = $content ? nl2br(e($content)) : '';

        // Формируем HTML для вложений
        $attachmentsHtml = '';
        if (!empty($attachments)) {
            $attachmentsHtml = '<div style="margin-top: 20px;">';

            foreach ($attachments as $attachment) {
                $attachmentsHtml .= $this->buildAttachmentHtml($attachment);
            }

            $attachmentsHtml .= '</div>';
        }

        return "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto;'>
                    {$textContent}
                    {$attachmentsHtml}
                    <hr style='margin-top: 30px; border: none; border-top: 1px solid #ddd;'>
                    <p style='font-size: 12px; color: #999; margin-top: 20px; text-align: center;'>
                        <a href='#' style='color: #0066cc; text-decoration: none;'>
                            Отписаться от рассылки
                        </a>
                    </p>
                </div>
            </body>
            </html>
        ";
    }

    /**
     * Построить HTML для одного вложения
     */
    private function buildAttachmentHtml(array $attachment): string
    {
        $type = $attachment['type'] ?? 'file';
        $url = $attachment['url'] ?? '';
        $fileName = $attachment['file_name'] ?? 'file';
        $fileSize = $this->formatFileSize($attachment['file_size'] ?? 0);

        // Для изображений показываем превью
        if ($type === 'image' && $url) {

            return "";

            return "
                <div style='margin-bottom: 15px;'>
                    <img src='{$url}' alt='{$fileName}' style='max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #ddd;'>
                   <p style='font-size: 12px; color: #666; margin-top: 5px;'>{$fileName} ({$fileSize})</p>
                </div>
            ";
        }

        // Для аудио и файлов показываем ссылку
        $icon = $type === 'audio' ? '🎵' : '📎';
        return "
            <div style='margin-bottom: 10px; padding: 10px; background: #f5f5f5; border-radius: 5px;'>
                <p style='margin: 0; font-size: 14px;'>
                    {$icon} <a href='{$url}' style='color: #0066cc; text-decoration: none;'>{$fileName}</a>
                    <span style='color: #999; font-size: 12px;'>({$fileSize})</span>
                </p>
            </div>
        ";
    }

    /**
     * Прикрепить файлы к письму
     */
    private function attachFiles($message, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            try {
                $filePath = $attachment['file_path'] ?? null;

                if (!$filePath) {
                    Log::warning("EmailAdapter: file_path not found in attachment", [
                        'attachment' => $attachment
                    ]);
                    continue;
                }

                // Получаем полный путь к файлу
                $fullPath = Storage::disk('public')->path($filePath);

                if (!file_exists($fullPath)) {
                    Log::error("EmailAdapter: File not found", [
                        'file_path' => $filePath,
                        'full_path' => $fullPath
                    ]);
                    continue;
                }

                $fileName = $attachment['file_name'] ?? basename($filePath);
                $mimeType = $attachment['mime_type'] ?? 'application/octet-stream';

                // Прикрепляем файл
                $message->attach($fullPath, [
                    'as' => $fileName,
                    'mime' => $mimeType,
                ]);

                Log::info("EmailAdapter: File attached", [
                    'file_name' => $fileName,
                    'size' => filesize($fullPath)
                ]);

            } catch (Exception $e) {
                Log::error("EmailAdapter: Failed to attach file", [
                    'attachment' => $attachment,
                    'error' => $e->getMessage()
                ]);
                // Продолжаем, даже если один файл не прикрепился
            }
        }
    }

    /**
     * Форматировать размер файла
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * Отметить сообщение как прочитанное
     */
    public function markAsRead(string $externalId): bool
    {
        // Email не имеет встроенного API для отметки как прочитано
        return true;
    }

    public function getSourceName(): string
    {
        return 'email';
    }
}
