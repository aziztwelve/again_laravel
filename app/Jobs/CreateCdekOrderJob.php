<?php

namespace App\Jobs;

use App\Models\CdekOrder;
use App\Models\Order;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use RuntimeException;

class CreateCdekOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $uniqueFor = 900;
    public function __construct(public int $orderId) {}
    public function uniqueId(): string { return (string) $this->orderId; }
    public function middleware(): array { return [(new WithoutOverlapping('cdek-order:'.$this->orderId))->expireAfter(900)]; }

    public function handle(CdekDeliveryService $service): void
    {
        $order = Order::query()->with('deliveryMethod')->findOrFail($this->orderId);
        if (! $order->isPaid() || ! str_starts_with((string) $order->deliveryMethod?->code, 'cdek_')) return;
        $delivery = $order->delivery_data ?? [];
        $cdekOrder = CdekOrder::firstOrCreate(['order_id' => $order->id], [
            'external_order_number' => 'order-'.$order->id,
            'delivery_type' => $delivery['delivery_type'] ?? (str_ends_with((string) $order->deliveryMethod?->code, 'pickup') ? 'pickup' : 'courier'),
            'delivery_mode' => $delivery['delivery_mode'] ?? null,
            'tariff_code' => $delivery['tariff_code'] ?? 0,
            'price' => $delivery['price'] ?? null,
            'pvz_code' => $delivery['pvz']['code'] ?? null,
            'creation_state' => 'NEW',
        ]);
        if ($cdekOrder->cdek_uuid || $cdekOrder->creation_state === 'INVALID') return;
        // POST /orders is asynchronous. While CDEK is still processing the first
        // request, poll it instead of registering the same shop order again.
        if ($cdekOrder->request_uuid) {
            SyncCdekOrderJob::dispatch($cdekOrder->id);
            return;
        }

        // Данные заказа неполные (частый случай — legacy-заказы без
        // delivery_data). Повтор ничего не изменит: сохраняем причину в заявку,
        // чтобы менеджер увидел её на странице СДЭК, и выходим без падения.
        try {
            $result = $service->createExternalOrder($order, $cdekOrder);
        } catch (InvalidArgumentException $exception) {
            $cdekOrder->update(['last_error' => $exception->getMessage()]);
            return;
        }

        if (! $result['successful']) {
            $cdekOrder->update(['last_error' => $this->apiError($result)]);
            throw new RuntimeException('CDEK order registration failed with HTTP '.$result['status']);
        }
        $request = $result['data']['requests'][0] ?? [];
        $cdekOrder->update([
            'request_uuid' => $request['request_uuid'] ?? null,
            'creation_state' => $request['state'] ?? 'ACCEPTED',
            'last_error' => ! empty($request['errors']) ? json_encode($request['errors'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        SyncCdekOrderJob::dispatch($cdekOrder->id)->delay(now()->addMinute());
    }

    /** Читаемый текст ошибки ответа СДЭК для карточки заявки. */
    private function apiError(array $result): string
    {
        $messages = collect(data_get($result, 'data.requests.*.errors.*.message'))
            ->merge(collect(data_get($result, 'data.errors.*.message')))
            ->filter()->unique()->implode('; ');

        return $messages !== ''
            ? $messages
            : (data_get($result, 'data.message') ?: 'СДЭК отклонил заявку (HTTP '.$result['status'].').');
    }
}
