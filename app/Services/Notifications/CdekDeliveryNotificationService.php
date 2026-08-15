<?php

namespace App\Services\Notifications;

use App\Models\CdekOrder;
use Illuminate\Support\Facades\Log;

class CdekDeliveryNotificationService
{
    // CDEK exposes technical codes; group equivalent transitions so customers
    // receive the same understandable delivery milestones as Yandex users.
    private const CUSTOMER_STATUSES = [
        'ACCEPTED' => 'handed_over',
        'RECEIVED_AT_SHIPMENT_WAREHOUSE' => 'handed_over',
        'READY_FOR_PICKUP' => 'ready_for_pickup',
        'ACCEPTED_AT_PICKUP_POINT' => 'ready_for_pickup',
        'DELIVERED' => 'delivered',
        'NOT_DELIVERED' => 'delivery_problem',
        'RETURNED_TO_SENDER' => 'returning',
    ];

    public function __construct(
        protected CdekDeliveryMessageBuilder $messageBuilder,
        protected CustomerChannelResolver $channelResolver,
        protected TransactionalNotificationDispatcher $dispatcher,
    ) {}

    public function notify(CdekOrder $cdekOrder, ?string $statusCode = null): void
    {
        $statusCode ??= $cdekOrder->status_code;
        $customerStatus = self::CUSTOMER_STATUSES[$statusCode] ?? null;
        if (! $customerStatus) return;

        $cdekOrder->loadMissing('order.client.profile');
        $order = $cdekOrder->order;
        $content = $this->messageBuilder->build($cdekOrder, $customerStatus);
        $eventKey = 'cdek_delivery.'.$customerStatus;

        foreach ($this->channelResolver->resolve($order->client, $order->email) as $recipient) {
            $data = ['type' => $eventKey, 'order_id' => $order->id, 'cdek_order_id' => $cdekOrder->id, 'status_code' => $statusCode, 'customer_status' => $customerStatus, 'tracking_url' => $cdekOrder->tracking_url, 'subject' => $content['subject'], 'mirror_conversation' => ['source' => $recipient['source'], 'external_id' => $recipient['recipient_id'], 'client_id' => $order->client_id]];
            if ($recipient['channel'] === 'email') $data['html'] = $content['html'];
            $this->dispatcher->dispatch($eventKey, 'cdek_order', $cdekOrder->id, $recipient, $content['message'], $data);
        }

        Log::info('CDEK delivery customer notifications queued', ['order_id' => $order->id, 'cdek_order_id' => $cdekOrder->id, 'status_code' => $statusCode]);
    }
}
