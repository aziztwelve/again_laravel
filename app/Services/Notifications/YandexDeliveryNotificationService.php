<?php

namespace App\Services\Notifications;

use App\Models\YandexOrder;
use Illuminate\Support\Facades\Log;

class YandexDeliveryNotificationService
{
    public function __construct(
        protected YandexDeliveryMessageBuilder $messageBuilder,
        protected CustomerChannelResolver $channelResolver,
        protected TransactionalNotificationDispatcher $dispatcher,
    ) {}

    public function notify(YandexOrder $yandexOrder): void
    {
        if (! $yandexOrder->customer_status) {
            return;
        }

        $yandexOrder->loadMissing('order.client.profile');
        $order = $yandexOrder->order;
        $content = $this->messageBuilder->build($yandexOrder, $yandexOrder->customer_status);
        $eventKey = 'yandex_delivery.'.$yandexOrder->customer_status;

        foreach ($this->channelResolver->resolve($order->client, $order->email) as $recipient) {
            $data = [
                'type' => $eventKey,
                'order_id' => $order->id,
                'yandex_order_id' => $yandexOrder->id,
                'customer_status' => $yandexOrder->customer_status,
                'tracking_url' => $yandexOrder->tracking_url,
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

            $this->dispatcher->dispatch(
                $eventKey,
                'yandex_order',
                $yandexOrder->id,
                $recipient,
                $content['message'],
                $data,
            );
        }

        Log::info('Yandex delivery customer notifications queued', [
            'order_id' => $order->id,
            'yandex_order_id' => $yandexOrder->id,
            'customer_status' => $yandexOrder->customer_status,
        ]);
    }
}
