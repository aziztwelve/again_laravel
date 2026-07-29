<?php

namespace App\Jobs;

use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollYandexDeliveryStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(YandexDeliveryService $service): void
    {
        YandexOrder::query()->whereNotNull('claim_id')
            ->whereNotIn('internal_status', ['delivered', 'cancelled', 'failed', 'returning'])
            ->orderBy('last_synced_at')->limit(100)->get()
            ->each(fn (YandexOrder $order) => $service->sync($order));
    }
}
