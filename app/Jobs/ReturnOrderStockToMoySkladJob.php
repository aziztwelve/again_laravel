<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MoySklad\DemandService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Возвращает товар по отменённому заказу на склад МойСклад: распроводит
 * документ «Отгрузка» (demand), созданный при выгрузке заказа (см.
 * DemandService::shipOrder(), вызывается из SyncOrderToMoySkladJob).
 * Идемпотентно — если demand ещё не был создан, ничего не делает.
 */
class ReturnOrderStockToMoySkladJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $orderId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('moysklad-return-stock:'.$this->orderId))->expireAfter(900)];
    }

    public function handle(DemandService $service): void
    {
        $order = Order::query()->findOrFail($this->orderId);

        try {
            $service->returnOrderStock($order);
        } catch (\Throwable $e) {
            Log::error('ReturnOrderStockToMoySkladJob: не удалось вернуть товар на склад', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
