<?php

namespace App\Observers;

use App\Models\Order;
use App\Events\OrderPaid;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Ручная отметка оплаты должна использовать тот же мост, что и
        // платёжные webhook-и, а не устаревший синхронный createShipment().
        if ($order->isDirty('payment_status') && $order->isPaid()) {
            OrderPaid::dispatch($order->fresh(['deliveryMethod']));
        }
    }
}
