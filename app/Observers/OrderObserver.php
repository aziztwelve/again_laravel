<?php

namespace App\Observers;

use App\Models\Order;
use App\Events\OrderPaid;
use App\Jobs\SyncOrderToMoySkladJob;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Ручная отметка оплаты должна использовать тот же мост, что и
        // платёжные webhook-и, а не устаревший синхронный createShipment().
        if ($order->isDirty('payment_status') && $order->isPaid()) {
            OrderPaid::dispatch($order->fresh(['deliveryMethod']));
        }

        // Трек-номер перевозчик назначает уже после оформления заказа: передаём его
        // в МойСклад, если заказ туда уже выгружен.
        if ($order->wasChanged('tracking_number') && filled($order->moysklad_order_uuid)) {
            SyncOrderToMoySkladJob::dispatch($order->id);
        }
    }
}
