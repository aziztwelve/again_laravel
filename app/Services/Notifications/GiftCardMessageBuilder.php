<?php

namespace App\Services\Notifications;

use App\Models\GiftCard\GiftCard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class GiftCardMessageBuilder
{
    public const EMAIL_SUBJECT = 'Подарочный сертификат на белье Again';

    /**
     * HTML письма получателю сертификата (канал email).
     */
    public function buildEmailHtml(GiftCard $giftCard): string
    {
        $nominal = (float) $giftCard->nominal;

        return view('emails.gift-card', [
            'subject' => self::EMAIL_SUBJECT,
            'name' => trim((string) $giftCard->recipient_name),
            'sender' => trim((string) $giftCard->sender_name),
            'message' => trim((string) $giftCard->message),
            'nominal' => number_format($nominal, floor($nominal) === $nominal ? 0 : 2, ',', ' ') . ' ₽',
            'code' => $giftCard->code,
            'imageUrl' => $this->certificateImageUrl($nominal),
            'shopUrl' => rtrim((string) config('app.frontend_url'), '/'),
            'supportLinks' => [
                ['label' => 'Написать в поддержку в WhatsApp', 'url' => 'https://wa.me/79213828888'],
                ['label' => 'Написать в поддержку в Telegram', 'url' => 'https://t.me/again8help_bot'],
                ['label' => 'Написать в поддержку в MAX', 'url' => 'https://max.ru/' . config('services.messenger_deeplinks.max_bot')],
            ],
        ])->render();
    }

    /**
     * Картинка сертификата по номиналу (storage/app/public/gift-certificates/2000.jpg).
     * Отдаётся nginx по /storage/, поэтому открывается и при закрытой витрине.
     * Для номинала без картинки блок в письме просто не выводится.
     */
    protected function certificateImageUrl(float $nominal): ?string
    {
        if ($nominal <= 0 || floor($nominal) !== $nominal) {
            return null;
        }

        $path = 'gift-certificates/' . (int) $nominal . '.jpg';

        return Storage::disk('public')->exists($path) ? url('/storage/' . $path) : null;
    }

    public function buildIssued(GiftCard $giftCard): string
    {
        return implode("\n", [
            'Подарочная карта успешно оформлена!',
            'Получатель: ' . ($giftCard->recipient_name ?: $giftCard->recipient_email ?: $giftCard->recipient_phone ?: 'Не указан'),
            'Номинал: ' . $this->money((float) $giftCard->nominal),
            'Код: ' . $giftCard->code,
        ]);
    }

    public function buildDeliveryConfirmation(GiftCard $giftCard): string
    {
        $deliveredAt = ($giftCard->delivered_at ?? $giftCard->sent_at ?? now())->format('d.m.Y H:i');

        return implode("\n", [
            'Ваша подарочная карта успешно доставлена!',
            'Получатель: ' . ($giftCard->recipient_name ?: $giftCard->recipient_email ?: $giftCard->recipient_phone ?: 'Не указан'),
            'Номинал: ' . number_format((float) $giftCard->nominal, 2, '.', '') . ' ₽',
            'Код: ' . $giftCard->code,
            'Доставлено: ' . $deliveredAt,
        ]);
    }

    protected function money(float $amount): string
    {
        return Number::currency($amount, 'RUB', locale: 'ru');
    }
}
