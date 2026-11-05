<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CdekDefaultTariffTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pickup_order_with_pvz_but_without_tariff_gets_default_tariff(): void
    {
        $order = Order::factory()->create(['delivery_data' => [
            'provider' => 'cdek', 'delivery_type' => 'pickup', 'pvz' => ['code' => 'VLK67', 'type' => 'PVZ'],
        ]]);

        app(CdekDeliveryService::class)->applyDefaultTariff($order);

        $this->assertSame(136, $order->fresh()->delivery_data['tariff_code']);
    }

    public function test_postamat_gets_postamat_tariff_and_existing_tariff_is_kept(): void
    {
        $postamat = Order::factory()->create(['delivery_data' => [
            'provider' => 'cdek', 'delivery_type' => 'postamat', 'pvz' => ['code' => 'MSK1', 'type' => 'POSTAMAT'],
        ]]);
        $kept = Order::factory()->create(['delivery_data' => [
            'provider' => 'cdek', 'delivery_type' => 'pickup', 'tariff_code' => 999, 'pvz' => ['code' => 'MSK2'],
        ]]);
        $service = app(CdekDeliveryService::class);

        $service->applyDefaultTariff($postamat);
        $service->applyDefaultTariff($kept);

        $this->assertSame(368, $postamat->fresh()->delivery_data['tariff_code']);
        $this->assertSame(999, $kept->fresh()->delivery_data['tariff_code']);
    }

    public function test_order_without_pvz_is_left_untouched(): void
    {
        $order = Order::factory()->create(['delivery_data' => ['provider' => 'cdek', 'delivery_type' => 'pickup']]);

        app(CdekDeliveryService::class)->applyDefaultTariff($order);

        $this->assertArrayNotHasKey('tariff_code', $order->fresh()->delivery_data);
    }
}
