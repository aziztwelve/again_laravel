<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\CdekOrder;
use App\Models\ShipmentStatus;
use App\Services\Order\OrderCreationService;
use Illuminate\Support\Facades\Log;

/**
 * Автоматически переключает статус заказа при изменении статуса доставки
 * СДЭК (см. CdekDeliveryService::sync(), запускается через
 * PollCdekDeliveryStatusesJob каждые 10 минут).
 *
 * Маппинг internal_status (CdekDeliveryService::internalStatus(),
 * ShipmentStatus-константы) -> OrderStatus:
 *   IN_TRANSIT -> SHIPPED   (заказ забран у отправителя, в пути)
 *   DELIVERED  -> DELIVERED (доставлено получателю/в ПВЗ)
 *   RETURNED   -> PRODUCT_RETURN (не забрали/не было дома, вернулся отправителю)
 *
 * CANCELLED здесь не обрабатывается — отмена заказа управляется отдельно
 * через OrderCreationService::cancelOrder(), чтобы не создавать двух
 * источников истины.
 *
 * См. docs/tasks/order-status-actualization.md, пункт 2.
 */
class CdekOrderObserver
{
    private const STATUS_MAP = [
        ShipmentStatus::IN_TRANSIT => OrderStatus::SHIPPED,
        ShipmentStatus::DELIVERED => OrderStatus::DELIVERED,
        ShipmentStatus::RETURNED => OrderStatus::PRODUCT_RETURN,
    ];

    public function updated(CdekOrder $cdekOrder): void
    {
        if (! $cdekOrder->isDirty('internal_status')) {
            return;
        }

        $newOrderStatus = self::STATUS_MAP[$cdekOrder->internal_status] ?? null;
        if (! $newOrderStatus) {
            return;
        }

        $order = $cdekOrder->order;
        if (! $order || $order->status === $newOrderStatus) {
            return;
        }

        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURN_PAYMENT], true)) {
            return;
        }

        try {
            app(OrderCreationService::class)->updateOrderStatus($order, $newOrderStatus);
        } catch (\Throwable $e) {
            Log::error('CdekOrderObserver: не удалось обновить статус заказа', [
                'order_id' => $order->id,
                'cdek_internal_status' => $cdekOrder->internal_status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
