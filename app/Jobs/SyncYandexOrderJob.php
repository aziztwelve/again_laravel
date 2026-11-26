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
 * Подтянуть данные заявки Яндекс.Доставки вскоре после создания. Номер грузоместа
 * (request.places[0].barcode → трек YD…) Яндекс присваивает не мгновенно, поэтому
 * запрос сразу после создания его ещё не содержит. Задача ставится с задержкой и повторяется, пока номер
 * не появится (плановый опрос статусов остаётся запасным вариантом).
 */
class SyncYandexOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Попыток с интервалом 90 с: около 25 минут. Яндекс присваивает номер через 10–20 минут после создания заявки. */
    public int $tries = 17;

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

        if (! $this->hasTrack($yandexOrder->fresh('order')) && $this->attempts() < $this->tries) {
            $this->release(90);
        }
    }

    /** Номер для заказа уже есть: у заказов с номером грузоместа это трек заказа, отличный от ID заявки. */
    private function hasTrack(YandexOrder $yandexOrder): bool
    {
        $order = $yandexOrder->order;
        if ($order && YandexDeliveryService::usesPackageTracking($order)) {
            $track = (string) $order->tracking_number;

            return $track !== '' && $track !== $yandexOrder->claim_id;
        }

        return filled($yandexOrder->tracking_number);
    }
}
