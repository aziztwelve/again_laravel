<?php

namespace App\Jobs;

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

    /**
     * DemandService резолвится внутри метода: его конструктор бросает
     * исключение при отсутствии настроек МойСклад, и контейнер сделал бы это
     * ещё до проверки isConfigured() ниже.
     */
    public function handle(): void
    {
        $order = Order::query()->findOrFail($this->orderId);

        if (! MoySkladSettings::isConfigured()) {
            Log::warning('ReturnOrderStockToMoySkladJob: МойСклад не настроен, возврат пропущен', [
                'order_id' => $order->id,
            ]);

            return;
        }

        try {
            app(DemandService::class)->returnOrderStock($order);
        } catch (\Throwable $e) {
            Log::error('ReturnOrderStockToMoySkladJob: не удалось вернуть товар на склад', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
