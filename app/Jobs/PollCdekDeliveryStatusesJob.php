<?php

namespace App\Jobs;

use App\Models\CdekOrder;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollCdekDeliveryStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CdekDeliveryService $service): void
    {
        CdekOrder::query()
            ->whereNotIn('internal_status', ['delivered', 'returned', 'cancelled'])
            ->orderBy('id')
            ->chunkById(100, fn ($orders) => $orders->each(fn (CdekOrder $order) => $service->sync($order)));
    }
}
