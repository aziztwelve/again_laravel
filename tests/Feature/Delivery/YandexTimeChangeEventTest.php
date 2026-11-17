<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Заказ 41193: событие Яндекса «Время доставки изменено» ставило заказу «Отгружен»,
 * хотя посылку никто не забирал.
 */
class YandexTimeChangeEventTest extends TestCase
{
    use DatabaseTransactions;

    private function save(Order $order, string $yandexStatus, ?YandexOrder $existing): YandexOrder
    {
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        $service = app(YandexDeliveryService::class);
        $method = new \ReflectionMethod($service, 'saveRequest');
        $method->setAccessible(true);

        return $method->invoke($service, $order, ['request_id' => 'claim-1-udp', 'state' => ['status' => $yandexStatus]], $existing);
    }

    private function orderInWork(): array
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::PROCESSING,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'offer_id' => 'offer-1'],
            'moysklad_order_uuid' => null,
        ]);
        $yandexOrder = YandexOrder::create([
            'order_id' => $order->id, 'claim_id' => 'claim-1-udp', 'status' => 'SORTING_CENTER_LOADED', 'internal_status' => 'created',
            'delivery_type' => 'pickup', 'request_id' => (string) Str::uuid(),
        ]);

        return [$order, $yandexOrder];
    }

    public function test_time_change_event_does_not_ship_the_order(): void
    {
        [$order, $yandexOrder] = $this->orderInWork();

        $saved = $this->save($order, 'DELIVERY_TIME_INTERVALS_UPDATED', $yandexOrder);

        $this->assertSame('created', $saved->internal_status);
        $this->assertSame('DELIVERY_TIME_INTERVALS_UPDATED', $saved->status);
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    public function test_time_change_event_does_not_roll_back_a_real_pickup(): void
    {
        [$order, $yandexOrder] = $this->orderInWork();
        $yandexOrder->update(['internal_status' => 'picked_up', 'status' => 'DELIVERY_TRANSPORTATION']);

        $saved = $this->save($order, 'DELIVERY_TIME_INTERVALS_UPDATED', $yandexOrder->fresh());

        $this->assertSame('picked_up', $saved->internal_status);
    }

    public function test_a_real_pickup_event_still_ships_the_order(): void
    {
        [$order, $yandexOrder] = $this->orderInWork();

        $this->save($order, 'DELIVERY_TRANSPORTATION', $yandexOrder);

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }
}
