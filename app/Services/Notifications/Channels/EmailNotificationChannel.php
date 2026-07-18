<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\BaseNotificationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

class EmailNotificationChannel extends BaseNotificationChannel
{
    public function send(string $recipientId, string $message, array $data = []): bool
    {
        try {
            Mail::html($data['html'] ?? $this->formatMessage($message), function (Message $mailMessage) use ($recipientId, $data) {
                $mailMessage->to($recipientId)
                    ->subject($data['subject'] ?? 'Уведомление');
            });

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
        return 'email';
    }


    protected function formatMessage(string $message): string
    {
        // Если сообщение уже содержит HTML теги - оставляем как есть
        if (str_contains($message, '<')) {
            $htmlContent = $message;
        } else {
            // Конвертируем текст в HTML (переносы строк в <br>)
            $htmlContent = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        }

        $footer = $this->footerHtml();

        return "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto;'>
                    {$htmlContent}

                    {$footer}
                </div>
            </body>
            </html>
        ";
    }

    private function footerHtml(): string
    {
        $address = e((string) config('mail.from.address'));
        $subject = rawurlencode('Отписка от рассылки AGAIN');

        return "
            <div style='margin-top: 30px; padding: 20px; background: #292725; color: #ffffff; font-size: 12px; line-height: 18px;'>
                © ".now()->year." AGAIN<br>
                Вы получили это письмо, потому что зарегистрировались на сайте AGAIN или запросили это уведомление.<br><br>
                Это сообщение отправлено вам от:<br>
                AGAIN | {$address}<br><br>
                <a href='mailto:{$address}?subject={$subject}' style='color: #ffffff; text-decoration: underline;'>Отписаться от рассылки</a>
            </div>
        ";
    }


}
