<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\CreateCdekOrderJob;

class CreateCdekOrderAfterPayment
{
    public function handle(OrderPaid $event): void
    {
        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'cdek_')) {
            CreateCdekOrderJob::dispatch($event->order->id);
        }
    }
}
