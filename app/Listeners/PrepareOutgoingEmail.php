<?php

namespace App\Listeners;

use App\Models\EmailUnsubscribe;
use App\Support\EmailBranding;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Единая точка для всех исходящих HTML-писем:
 *  - подставляет в подвал личную ссылку на отписку (подписанную, привязана к адресу получателя);
 *  - не отправляет рекламное письмо (метка MARKETING_MARKER) адресу, который от рекламы отписался.
 * Письма о заказах (принят, оплачен, отправлен, чек) метки не имеют и отписку не учитывают.
 */
class PrepareOutgoingEmail
{
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        $html = $message->getHtmlBody();
        if (! is_string($html) || $html === '') {
            return null;
        }

        $recipients = $message->getTo();
        $email = $recipients !== [] ? $recipients[0]->getAddress() : null;

        if ($email !== null && str_contains($html, EmailBranding::MARKETING_MARKER) && EmailUnsubscribe::isUnsubscribed($email)) {
            Log::info('Marketing email skipped: recipient unsubscribed', ['email' => $email]);

            return false;
        }

        if (str_contains($html, EmailBranding::UNSUBSCRIBE_PLACEHOLDER)) {
            $url = $email !== null
                ? URL::signedRoute('email.unsubscribe', ['email' => EmailUnsubscribe::normalize($email)])
                : \App\Support\PublicUrl::base();
            $message->html(str_replace(EmailBranding::UNSUBSCRIBE_PLACEHOLDER, $url, $html));
        }

        return null;
    }
}
