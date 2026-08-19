<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\YandexOrder;
use App\Services\Order\OrderCreationService;
use Illuminate\Support\Facades\Log;

/**
 * Автоматически переключает статус заказа при изменении статуса доставки
 * Яндекс.Доставки (см. YandexDeliveryService::sync(), запускается через
 * PollYandexDeliveryStatusesJob каждые 10 минут).
 *
 * Маппинг internal_status (App\Services\Delivery\Yandex\StatusMapper) ->
 * OrderStatus:
 *   picked_up            -> SHIPPED   (курьер забрал заказ у отправителя)
 *   delivered            -> DELIVERED (доставлено получателю/в ПВЗ)
 *   returning / returned -> PRODUCT_RETURN (не забрали, отправлено обратно)
 *
 * cancelled/failed НЕ обрабатываются здесь: отмена заказа управляется
 * отдельно через OrderCreationService::cancelOrder() (ручная/крон), чтобы
 * не создавать двух источников истины для отмены.
 *
 * См. docs/tasks/order-status-actualization.md, пункт 2.
 */
class YandexOrderObserver
{
    private const STATUS_MAP = [
        'picked_up' => OrderStatus::SHIPPED,
        'delivered' => OrderStatus::DELIVERED,
        'returning' => OrderStatus::PRODUCT_RETURN,
        'returned' => OrderStatus::PRODUCT_RETURN,
    ];

    public function updated(YandexOrder $yandexOrder): void
    {
        if (! $yandexOrder->isDirty('internal_status')) {
            return;
        }

        $newOrderStatus = self::STATUS_MAP[$yandexOrder->internal_status] ?? null;
        if (! $newOrderStatus) {
            return;
        }

        $order = $yandexOrder->order;
        if (! $order || $order->status === $newOrderStatus) {
            return;
        }

        // Не откатываем финальные/ручные статусы автоматикой доставки —
        // например, если заказ уже отменён или это возврат оплаты, статус
        // доставки не должен его переписать обратно на "Отгружен".
        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURN_PAYMENT], true)) {
            return;
        }

        try {
            app(OrderCreationService::class)->updateOrderStatus($order, $newOrderStatus);
        } catch (\Throwable $e) {
            Log::error('YandexOrderObserver: не удалось обновить статус заказа', [
                'order_id' => $order->id,
                'yandex_internal_status' => $yandexOrder->internal_status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
