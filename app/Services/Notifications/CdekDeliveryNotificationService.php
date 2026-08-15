<?php

namespace App\Services\Notifications;

use App\Models\CdekOrder;
use Illuminate\Support\Facades\Log;

class CdekDeliveryNotificationService
{
    private const NOTIFIABLE_STATUSES = ['ACCEPTED', 'READY_FOR_PICKUP', 'DELIVERED', 'NOT_DELIVERED', 'RETURNED_TO_SENDER'];

    public function __construct(
        protected CdekDeliveryMessageBuilder $messageBuilder,
        protected CustomerChannelResolver $channelResolver,
        protected TransactionalNotificationDispatcher $dispatcher,
    ) {}

    public function notify(CdekOrder $cdekOrder, ?string $statusCode = null): void
    {
        $statusCode ??= $cdekOrder->status_code;
        if (! in_array($statusCode, self::NOTIFIABLE_STATUSES, true)) return;

        $cdekOrder->loadMissing('order.client.profile');
        $order = $cdekOrder->order;
        $content = $this->messageBuilder->build($cdekOrder, $statusCode);
        $eventKey = 'cdek_delivery.'.strtolower($statusCode);

        foreach ($this->channelResolver->resolve($order->client, $order->email) as $recipient) {
            $data = ['type' => $eventKey, 'order_id' => $order->id, 'cdek_order_id' => $cdekOrder->id, 'status_code' => $statusCode, 'tracking_url' => $cdekOrder->tracking_url, 'subject' => $content['subject'], 'mirror_conversation' => ['source' => $recipient['source'], 'external_id' => $recipient['recipient_id'], 'client_id' => $order->client_id]];
            if ($recipient['channel'] === 'email') $data['html'] = $content['html'];
            $this->dispatcher->dispatch($eventKey, 'cdek_order', $cdekOrder->id, $recipient, $content['message'], $data);
        }

        Log::info('CDEK delivery customer notifications queued', ['order_id' => $order->id, 'cdek_order_id' => $cdekOrder->id, 'status_code' => $statusCode]);
    }
}
