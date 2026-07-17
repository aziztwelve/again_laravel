<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\CreateYandexOrderJob;

class CreateYandexOrderAfterPayment
{
    public function handle(OrderPaid $event): void
    {
        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'yandex_') && ! $event->order->yandexOrder()->exists()) {
            CreateYandexOrderJob::dispatch($event->order->id);
        }
    }
}
