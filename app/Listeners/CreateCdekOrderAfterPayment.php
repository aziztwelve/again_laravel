<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\CreateCdekOrderJob;
use App\Models\CdekOrder;

class CreateCdekOrderAfterPayment
{
    public function handle(OrderPaid $event): void
    {
        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'cdek_') && ! CdekOrder::query()->where('order_id', $event->order->id)->exists()) {
            CreateCdekOrderJob::dispatch($event->order->id);
        }
    }
}
