<?php

namespace App\Services\GiftCard;

use App\Enums\CommunicationChannel;
use App\Models\GiftCard\GiftCard;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Exception;

class GiftCardDeliveryService
{
    public function __construct(
        protected OrderNotificationService $orderNotificationService
    ) {}

    /**
     * Отправить подарочную карту получателю
     */
    public function send(GiftCard $giftCard): bool
    {
        try {
            $channel = $this->resolveChannel($giftCard->delivery_channel);
            $recipient = $this->resolveRecipient($giftCard);
            $message = $this->buildMessage($giftCard);
            $data = $this->buildData($giftCard);

            SendNotificationJob::dispatch($channel, $recipient, $message, $data);

            // Отмечаем как отправленную
            $giftCard->markAsSent();

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send gift card', [
                'gift_card_id' => $giftCard->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Определить канал доставки
     */
    protected function resolveChannel(string $deliveryChannel): string
    {
        return match ($deliveryChannel) {
            GiftCard::CHANNEL_EMAIL => CommunicationChannel::EMAIL->value,
            GiftCard::CHANNEL_WHATSAPP => CommunicationChannel::WHATSAPP->value,
            GiftCard::CHANNEL_SMS => 'sms',
            GiftCard::CHANNEL_TELEGRAM => CommunicationChannel::TELEGRAM->value,
            GiftCard::CHANNEL_MAX => CommunicationChannel::MAX->value,
            default => CommunicationChannel::EMAIL->value,
        };
    }

    /**
     * Определить получателя (email или telegram_id)
     */
    protected function resolveRecipient(GiftCard $giftCard): string
    {
        return match ($giftCard->delivery_channel) {
            GiftCard::CHANNEL_EMAIL => $giftCard->recipient_email,
            GiftCard::CHANNEL_WHATSAPP => $giftCard->recipient_phone,
            GiftCard::CHANNEL_SMS => $giftCard->recipient_phone,
            GiftCard::CHANNEL_TELEGRAM => $giftCard->recipient_phone,
            GiftCard::CHANNEL_MAX => $giftCard->recipient_phone,
            default => $giftCard->recipient_email,
        };
    }

    /**
     * Построить сообщение
     */
    protected function buildMessage(GiftCard $giftCard): string
    {
        $greeting = $giftCard->recipient_name
            ? "Здравствуйте, {$giftCard->recipient_name}!"
            : "Здравствуйте!";

        $from = $giftCard->sender_name
            ? " от {$giftCard->sender_name}"
            : "";

        $personalMessage = $giftCard->message
            ? "\n\n💌 Сообщение{$from}:\n\"{$giftCard->message}\""
            : "";

        $frontendUrl = config('app.frontend_url');
        $shopUrl = rtrim((string) $frontendUrl, '/');

        return <<<MSG
{$greeting}

🎁 Вам отправлена подарочная карта на сумму {$giftCard->nominal} ₽{$from}!

📋 Код карты: {$giftCard->code}
💳 Баланс: {$giftCard->balance} ₽
{$personalMessage}

Чтобы использовать карту, введите код при оформлении заказа на нашем сайте:
{$shopUrl}

С уважением,
Команда AGAIN
MSG;
    }

    /**
     * Построить дополнительные данные
     */
    protected function buildData(GiftCard $giftCard): array
    {
        return [
            'gift_card_id' => $giftCard->id,
            'code' => $giftCard->code,
            'nominal' => $giftCard->nominal,
            'balance' => $giftCard->balance,
            'sender_name' => $giftCard->sender_name,
            'recipient_name' => $giftCard->recipient_name,
            'message' => $giftCard->message,
            'type' => 'gift_card',
        ];
    }

    /**
     * Отправить уведомление покупателю о доставке карты получателю
     */
    public function sendDeliveryConfirmation(GiftCard $giftCard): void
    {
        try {
            if (!$giftCard->purchaseOrder) {
                return;
            }

            $this->orderNotificationService->notifyGiftCardDelivered($giftCard);

            Log::info('Gift card delivery confirmation sent', [
                'gift_card_id' => $giftCard->id,
                'order_id' => $giftCard->purchase_order_id,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send delivery confirmation', [
                'gift_card_id' => $giftCard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Сообщение о доставке для покупателя
     */
    protected function buildDeliveryConfirmationMessage(GiftCard $giftCard): string
    {
        $recipient = $giftCard->recipient_name ?? $giftCard->recipient_email;

        return <<<MSG
Ваша подарочная карта успешно доставлена!

Получатель: {$recipient}
Номинал: {$giftCard->nominal} ₽
Код: {$giftCard->code}
Доставлено: {$giftCard->sent_at->format('d.m.Y H:i')}

Заказ #{$giftCard->purchaseOrder->id}

С уважением,
Команда AGAIN
MSG;
    }
}
