<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;

class CreateYandexOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $orderId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('yandex-order:'.$this->orderId))->expireAfter(900)];
    }

    public function handle(YandexDeliveryService $service): void
    {
        $order = Order::query()->with(['deliveryMethod', 'yandexOrder'])->findOrFail($this->orderId);
        if (! $order->isPaid() || ! str_starts_with((string) $order->deliveryMethod?->code, 'yandex_')) {
            return;
        }

        $yandexOrder = YandexOrder::firstOrCreate(['order_id' => $order->id], [
            'request_id' => (string) Str::uuid(),
            'delivery_type' => $order->delivery_data['delivery_type'] ?? 'courier',
            'tariff_code' => $order->delivery_data['tariff_code'] ?? null,
            'offer_id' => $order->delivery_data['offer_id'] ?? null,
            'pvz_id' => $order->delivery_data['pvz']['id'] ?? null,
            'scheduled_time' => $order->delivery_data['scheduled_time'] ?? null,
            'price' => $order->delivery_data['price'] ?? null,
            'status' => 'new',
            'internal_status' => 'created',
        ]);
        if ($yandexOrder->claim_id) {
            return;
        }

        // The selected offer is confirmed only after successful payment. If it
        // has expired, request/create is used as a safe fallback.
        $created = $service->confirmOfferForOrder($order, $yandexOrder);
        if (! $created['successful']) {
            throw new RuntimeException('Yandex request/create failed with HTTP '.$created['status']);
        }

        $yandexOrder->refresh();
        if (! $yandexOrder->claim_id) {
            throw new RuntimeException('Yandex request/create did not return request_id');
        }
        $service->sync($yandexOrder->refresh());
    }
}
