<?php

namespace App\Jobs;

use App\Models\CdekOrder;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncCdekOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public int $cdekOrderId) {}
    public function middleware(): array { return [(new WithoutOverlapping('cdek-sync:'.$this->cdekOrderId))->expireAfter(120)]; }
    public function handle(CdekDeliveryService $service): void
    {
        $cdekOrder = CdekOrder::find($this->cdekOrderId);
        if ($cdekOrder) $service->sync($cdekOrder);
    }
}
