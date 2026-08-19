<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\SyncOrderToMoySkladJob;

class SyncOrderToMoySkladAfterPayment
{
    public function handle(OrderPaid $event): void
    {
        SyncOrderToMoySkladJob::dispatch($event->order->id);
    }
}
