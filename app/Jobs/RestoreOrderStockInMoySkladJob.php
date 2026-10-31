<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\MoySklad\DemandService;
use App\Services\MoySklad\MoySkladSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestoreOrderStockInMoySkladJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $orderId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('moysklad-stock:'.$this->orderId))->shared()->expireAfter(900)];
    }

    public function handle(): void
    {
        $order = Order::query()->findOrFail($this->orderId);
        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::PRODUCT_RETURN], true) || ! $order->moysklad_demand_uuid) {
            return;
        }
        if (! MoySkladSettings::isConfigured()) {
            Log::warning('МойСклад не настроен: повторное проведение отгрузки пропущено', ['order_id' => $order->id]);
            return;
        }
        app(DemandService::class)->restoreOrderStock($order);
    }
}
