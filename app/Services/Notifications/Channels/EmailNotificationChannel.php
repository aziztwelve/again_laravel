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

        // Шапка с логотипом и подвал одинаковые во всех письмах (emails.shell).
        return view('emails.shell', ['title' => 'AGAIN', 'body' => $htmlContent])->render();
    }
}
