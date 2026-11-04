<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\YandexDeliveryService;
use Mockery;
use Tests\TestCase;

/**
 * Ручной заказ Яндекс.Доставки из админки приходит без цены. Сервер берёт цену
 * у Яндекса, иначе доставка получалась бесплатной из-за отсутствия цены.
 */
class YandexCompleteDeliveryTest extends TestCase
{
    private const ITEMS = [['name' => 'Трусы', 'final_price' => 1, 'quantity' => 2]];

    private function service(?string $methodCode): YandexDeliveryService
    {
        $service = Mockery::mock(YandexDeliveryService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('resolveMethodCode')->andReturn($methodCode);

        return $service;
    }

    private function pickupPayload(): array
    {
        return [
            'delivery_method' => ['name' => 'Пункт самовывоза Яндекс.Доставки'],
            'pvz_code' => 'PVZ-1',
            'delivery_data' => ['delivery_type' => 'pickup'],
            'recipient' => ['first_name' => 'Анна', 'last_name' => 'Иванова', 'phone' => '+79990001122'],
        ];
    }

    public function test_pickup_without_price_gets_the_cheapest_provider_price(): void
    {
        $service = $this->service('yandex_pickup');
        $service->shouldReceive('calculateOffers')->once()
            ->with('pickup', Mockery::on(fn ($items) => $items[0]['quantity'] === 2 && $items[0]['price'] === 1.0 && $items[0]['weight'] === 500), 'PVZ-1', null, null, ['name' => 'Анна Иванова', 'phone' => '+79990001122'])
            ->andReturn([
                // порог витрины уже обнулил цену, тарифная цена в provider_price
                ['offer_id' => 'a', 'price' => 0.0, 'provider_price' => 420.0],
                ['offer_id' => 'b', 'price' => 300.0],
            ]);

        $result = $service->completeDelivery($this->pickupPayload(), self::ITEMS);

        $this->assertSame(300.0, $result['price']);
        $this->assertSame('pickup', $result['delivery_type']);
        $this->assertArrayNotHasKey('offer_id', $result, 'Короткоживущее предложение в заказ не пишем.');
    }

    public function test_courier_uses_address_from_the_order(): void
    {
        $service = $this->service('yandex_courier');
        $service->shouldReceive('calculateOffers')->once()
            ->with('courier', Mockery::type('array'), null, null, ['address' => 'Самара, ул. Мира, 1', 'coordinates' => [50.1, 53.2]], Mockery::type('array'))
            ->andReturn([['offer_id' => 'a', 'price' => 459.94]]);

        $result = $service->completeDelivery([
            'delivery_method' => ['name' => 'Курьером Яндекс.Доставки'],
            'delivery_address' => ['city' => 'Самара', 'address' => 'ул. Мира, 1'],
            'destination_coordinates' => [50.1, 53.2],
        ], self::ITEMS);

        $this->assertSame(459.94, $result['price']);
        $this->assertSame('courier', $result['delivery_type']);
    }

    public function test_any_existing_price_is_left_untouched_even_zero(): void
    {
        $service = $this->service('yandex_pickup');
        $service->shouldNotReceive('calculateOffers');
        $payload = $this->pickupPayload();
        $payload['delivery_data'] = ['delivery_type' => 'pickup', 'price' => 0];

        $this->assertSame($payload['delivery_data'], $service->completeDelivery($payload, self::ITEMS));
    }

    public function test_other_delivery_services_are_not_touched(): void
    {
        $service = $this->service('cdek_pickup');
        $service->shouldNotReceive('calculateOffers');
        $payload = ['delivery_method' => ['name' => 'Пункт выдачи СДЭК'], 'delivery_data' => ['provider' => 'cdek', 'delivery_type' => 'pickup']];

        $this->assertSame($payload['delivery_data'], $service->completeDelivery($payload, self::ITEMS));
    }

    public function test_order_without_delivery_is_not_touched(): void
    {
        $service = $this->service(null);
        $service->shouldNotReceive('calculateOffers');

        $this->assertSame([], $service->completeDelivery(['delivery_method' => ['name' => 'Доставка не требуется']], self::ITEMS));
    }

    public function test_order_is_not_blocked_when_yandex_returns_no_price(): void
    {
        $service = $this->service('yandex_pickup');
        $service->shouldReceive('calculateOffers')->andReturn([]);

        $this->assertSame(['delivery_type' => 'pickup'], $service->completeDelivery($this->pickupPayload(), self::ITEMS));
    }

    public function test_yandex_failure_does_not_break_order_creation(): void
    {
        $service = $this->service('yandex_pickup');
        $service->shouldReceive('calculateOffers')->andThrow(new \InvalidArgumentException('Яндекс.Доставка не приняла номер получателя.'));

        $this->assertSame(['delivery_type' => 'pickup'], $service->completeDelivery($this->pickupPayload(), self::ITEMS));
    }
}
