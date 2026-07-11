<?php

namespace App\Services\Notifications;

use App\Enums\CommunicationChannel;
use App\Models\Client;
use App\Models\GiftCard\GiftCard;
use App\Models\Order;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Log;

class OrderNotificationService
{
    public function __construct(
        protected OrderMessageBuilder $orderMessageBuilder,
        protected GiftCardMessageBuilder $giftCardMessageBuilder
    ) {}

    public function notifyOrderCreated(Order $order, ?Client $client = null): void
    {
        $client ??= $order->client;
        $message = $this->orderMessageBuilder->buildOrderCreated($order);

        $this->dispatchToCustomerChannels($order, $client, $message, [
            'type' => 'order_created',
            'order_id' => $order->id,
            'view_token' => $order->view_token,
            'subject' => 'Заказ № ' . ($order->order_number ?? $order->id),
        ]);
    }

    public function notifyGiftCardIssued(GiftCard $giftCard): void
    {
        $giftCard->loadMissing('purchaseOrder.client.profile');
        $order = $giftCard->purchaseOrder;

        if (!$order) {
            return;
        }

        $this->dispatchToCustomerChannels($order, $order->client, $this->giftCardMessageBuilder->buildIssued($giftCard), [
            'type' => 'gift_card_issued',
            'order_id' => $order->id,
            'gift_card_id' => $giftCard->id,
            'subject' => 'Подарочная карта оформлена',
        ]);
    }

    public function notifyGiftCardDelivered(GiftCard $giftCard): void
    {
        $giftCard->loadMissing('purchaseOrder.client.profile');
        $order = $giftCard->purchaseOrder;

        if (!$order) {
            return;
        }

        $this->dispatchToCustomerChannels($order, $order->client, $this->giftCardMessageBuilder->buildDeliveryConfirmation($giftCard), [
            'type' => 'gift_card_delivered',
            'order_id' => $order->id,
            'gift_card_id' => $giftCard->id,
            'subject' => 'Подарочная карта доставлена',
        ]);
    }

    protected function dispatchToCustomerChannels(Order $order, ?Client $client, string $message, array $data): void
    {
        foreach ($this->customerRecipients($order, $client) as $recipient) {
            SendNotificationJob::dispatch(
                $recipient['channel'],
                $recipient['recipient_id'],
                $message,
                array_merge($data, [
                    'mirror_conversation' => [
                        'source' => $recipient['source'],
                        'external_id' => $recipient['recipient_id'],
                        'client_id' => $client?->id,
                    ],
                ])
            );
        }

        Log::info('Customer notifications queued', [
            'order_id' => $order->id,
            'type' => $data['type'] ?? null,
            'client_id' => $client?->id,
        ]);
    }

    protected function customerRecipients(Order $order, ?Client $client): array
    {
        $client?->loadMissing('profile');
        $profile = $client?->profile;
        $recipients = [];

        $email = $client?->email ?: $order->email;
        if ($email) {
            $recipients[] = [
                'channel' => CommunicationChannel::EMAIL->value,
                'source' => CommunicationChannel::EMAIL->value,
                'recipient_id' => (string) $email,
            ];
        }

        $telegramId = $profile?->telegram_chat_id ?: $profile?->telegram_user_id;
        if ($telegramId) {
            $recipients[] = [
                'channel' => CommunicationChannel::TELEGRAM->value,
                'source' => CommunicationChannel::TELEGRAM->value,
                'recipient_id' => (string) $telegramId,
            ];
        }

        if ($profile?->max_user_id) {
            $recipients[] = [
                'channel' => CommunicationChannel::MAX->value,
                'source' => CommunicationChannel::MAX->value,
                'recipient_id' => (string) $profile->max_user_id,
            ];
        }

        return $recipients;
    }
}
