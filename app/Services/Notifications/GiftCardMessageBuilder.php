<?php

namespace App\Services\Notifications;

use App\Models\GiftCard\GiftCard;
use Illuminate\Support\Number;

class GiftCardMessageBuilder
{
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
