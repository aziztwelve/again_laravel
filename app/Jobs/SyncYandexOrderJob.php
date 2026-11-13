<?php

namespace App\Jobs;

use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Подтянуть данные заявки Яндекс.Доставки вскоре после создания. Номер партнёра по доставке
 * (courier_order_id → трек LO-…) Яндекс присваивает не мгновенно, а примерно через полминуты, поэтому
 * запрос сразу после создания его ещё не содержит. Задача ставится с задержкой и повторяется, пока номер
 * не появится (плановый опрос статусов остаётся запасным вариантом).
 */
class SyncYandexOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public int $yandexOrderId)
    {
    }

    public function handle(YandexDeliveryService $service): void
    {
        $yandexOrder = YandexOrder::query()->find($this->yandexOrderId);
        if (! $yandexOrder || ! $yandexOrder->claim_id) {
            return;
        }

        $service->sync($yandexOrder);

        if (blank($yandexOrder->fresh()->tracking_number) && $this->attempts() < $this->tries) {
            $this->release(90);
        }
    }
}
