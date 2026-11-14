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

    /**
     * Письмо «Оплата получена». Один раз на заказ и канал (dispatcher). Не отправляется для подарочных
     * карт (у них свои сообщения), заказов без суммы, давних оплат и старых заказов (см. config/order_notifications.php).
     */
    public function notifyOrderPaid(Order $order): void
    {
        if (! $this->shouldNotifyPaid($order)) {
            return;
        }

        $content = $this->orderMessageBuilder->buildOrderPaid($order);

        foreach ($this->customerChannelResolver->resolve($order->client, $order->email) as $recipient) {
            $data = [
                'type' => 'order_paid',
                'order_id' => $order->id,
                'view_token' => $order->view_token,
                'subject' => $content['subject'],
                'mirror_conversation' => [
                    'source' => $recipient['source'],
                    'external_id' => $recipient['recipient_id'],
                    'client_id' => $order->client_id,
                ],
            ];
            if ($recipient['channel'] === 'email') {
                $data['html'] = $content['html'];
            }

            $this->transactionalNotificationDispatcher->dispatch(
                'order_paid',
                'order',
                (int) $order->id,
                $recipient,
                OrderMessageText::forChannel($recipient['channel'], $content['message']),
                $data,
            );
        }

        Log::info('Order paid notifications queued', ['order_id' => $order->id, 'client_id' => $order->client_id]);
    }

    public function shouldNotifyPaid(Order $order): bool
    {
        if (! config('order_notifications.paid.enabled', true) || (float) $order->total_amount <= 0) {
            return false;
        }

        if (GiftCard::query()->where('purchase_order_id', $order->id)->exists()) {
            return false;
        }

        $maxPaidAge = (int) config('order_notifications.paid.max_paid_age_minutes', 120);
        if ($maxPaidAge > 0 && $order->paid_at && $order->paid_at->lt(now()->subMinutes($maxPaidAge))) {
            return false;
        }

        $maxOrderAge = (int) config('order_notifications.paid.max_order_age_days', 7);
        if ($maxOrderAge > 0 && $order->created_at && $order->created_at->lt(now()->subDays($maxOrderAge))) {
            return false;
        }

        return true;
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
