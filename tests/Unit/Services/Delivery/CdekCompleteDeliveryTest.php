<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\CdekDeliveryService;
use Mockery;
use Tests\TestCase;

/**
 * Ручной заказ СДЭК из админки приходит без тарифа и цены. Сервер считает
 * тариф сам, иначе доставка получалась бесплатной из-за отсутствия цены.
 */
class CdekCompleteDeliveryTest extends TestCase
{
    private const ITEMS = [['name' => 'Трусы', 'final_price' => 1, 'quantity' => 1]];

    private function service(): CdekDeliveryService
    {
        return Mockery::mock(CdekDeliveryService::class)->makePartial();
    }

    private function pickupWithoutTariff(): array
    {
        return [
            'provider' => 'cdek',
            'delivery_type' => 'pickup',
            'currency' => 'RUB',
            'pvz' => ['code' => 'SAM43', 'type' => 'PVZ', 'address' => 'ул. Ново-Садовая, 6'],
        ];
    }

    public function test_missing_tariff_is_calculated_with_city_taken_from_the_pickup_point(): void
    {
        $service = $this->service();
        $service->shouldReceive('pickupPoints')->once()
            ->with(['code' => 'SAM43', 'type' => 'ALL'])
            ->andReturn([['code' => 'SAM43', 'location' => ['city_code' => 430]]]);
        $service->shouldReceive('calculateTariffs')->once()
            ->with('pickup', ['city_code' => 430], Mockery::type('array'), 'SAM43')
            ->andReturn([
                ['tariff_code' => 483, 'tariff_name' => 'Экспресс', 'price' => 640.0, 'delivery_mode' => 4],
                ['tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'price' => 390.0, 'delivery_mode' => 4],
            ]);

        $result = $service->completeDelivery($this->pickupWithoutTariff(), self::ITEMS);

        $this->assertSame(136, $result['tariff_code']);
        $this->assertSame(390.0, $result['price']);
        $this->assertSame(['city_code' => 430], $result['destination']);
        $this->assertSame('SAM43', $result['pvz']['code']);
        $this->assertSame('cdek', $result['provider']);
        $this->assertSame('pickup', $result['delivery_type']);
    }

    public function test_delivery_that_already_has_tariff_and_price_is_left_untouched(): void
    {
        $service = $this->service();
        $service->shouldNotReceive('pickupPoints');
        $service->shouldNotReceive('calculateTariffs');
        $delivery = $this->pickupWithoutTariff() + ['tariff_code' => 136, 'price' => 390];

        $this->assertSame($delivery, $service->completeDelivery($delivery, self::ITEMS));
    }

    public function test_tariff_chosen_by_manager_is_kept_even_if_it_is_not_the_cheapest(): void
    {
        $service = $this->service();
        $service->shouldReceive('calculateTariffs')->once()->andReturn([
            ['tariff_code' => 136, 'price' => 390.0],
            ['tariff_code' => 483, 'price' => 640.0],
        ]);
        $delivery = $this->pickupWithoutTariff() + ['tariff_code' => 483, 'destination' => ['city_code' => 430]];

        $result = $service->completeDelivery($delivery, self::ITEMS);

        $this->assertSame(483, $result['tariff_code']);
        $this->assertSame(640.0, $result['price']);
    }

    public function test_order_is_not_blocked_when_no_tariff_can_be_calculated(): void
    {
        $service = $this->service();
        $service->shouldReceive('pickupPoints')->andReturn([['location' => ['city_code' => 430]]]);
        $service->shouldReceive('calculateTariffs')->andReturn([]);
        $delivery = $this->pickupWithoutTariff();

        $this->assertSame($delivery, $service->completeDelivery($delivery, self::ITEMS));
    }

    public function test_cdek_failure_does_not_break_order_creation(): void
    {
        $service = $this->service();
        $service->shouldReceive('pickupPoints')->andThrow(new \RuntimeException('CDEK unavailable'));
        $delivery = $this->pickupWithoutTariff();

        $this->assertSame($delivery, $service->completeDelivery($delivery, self::ITEMS));
    }

    public function test_courier_without_city_and_address_is_left_as_is(): void
    {
        $service = $this->service();
        $service->shouldReceive('calculateTariffs')->andThrow(new \InvalidArgumentException('Для курьерской доставки укажите город СДЭК и адрес.'));
        $delivery = ['provider' => 'cdek', 'delivery_type' => 'courier'];

        $this->assertSame($delivery, $service->completeDelivery($delivery, self::ITEMS));
    }
}
