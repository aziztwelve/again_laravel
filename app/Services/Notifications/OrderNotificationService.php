<?php

namespace App\Services\Notifications;

use App\Models\Client;
use App\Models\GiftCard\GiftCard;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class OrderNotificationService
{
    public function __construct(
        protected OrderMessageBuilder $orderMessageBuilder,
        protected GiftCardMessageBuilder $giftCardMessageBuilder,
        protected CustomerChannelResolver $customerChannelResolver,
        protected TransactionalNotificationDispatcher $transactionalNotificationDispatcher,
    ) {}

    public function notifyOrderCreated(Order $order, ?Client $client = null): void
    {
        $client ??= $order->client;
        $message = $this->orderMessageBuilder->buildOrderCreated($order);

        $this->dispatchToCustomerChannels($order, $client, $message, [
            'type' => 'order_created',
            'order_id' => $order->id,
            'view_token' => $order->view_token,
            'subject' => 'Заказ № '.($order->order_number ?? $order->id),
        ]);
    }

    public function notifyGiftCardIssued(GiftCard $giftCard): void
    {
        $giftCard->loadMissing('purchaseOrder.client.profile');
        $order = $giftCard->purchaseOrder;

        if (! $order) {
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

        if (! $order) {
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
        $giftCardId = $data['gift_card_id'] ?? null;
        $entityType = $giftCardId ? 'gift_card' : 'order';
        $entityId = (int) ($giftCardId ?: $order->id);

        foreach ($this->customerChannelResolver->resolve($client, $order->email) as $recipient) {
            $this->transactionalNotificationDispatcher->dispatch(
                $data['type'],
                $entityType,
                $entityId,
                $recipient,
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
}
