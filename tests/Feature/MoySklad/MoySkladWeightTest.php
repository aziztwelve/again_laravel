<?php

namespace Tests\Feature\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Services\MoySklad\ProductsAndVariantsSyncWithMoySkladService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MoySkladWeightTest extends TestCase
{
    use DatabaseTransactions;

    public function test_moysklad_weight_is_kept_in_grams_for_delivery_calculations(): void
    {
        DeliveryServiceSetting::firstOrCreate(
            ['service_name' => 'moysklad'],
            ['token' => 'test-token'],
        );

        $service = app(ProductsAndVariantsSyncWithMoySkladService::class);
        $method = new \ReflectionMethod($service, 'extractWeight');

        $this->assertSame(1500.0, $method->invoke($service, (object) ['weight' => 1500]));
        $this->assertSame(89.0, $method->invoke($service, (object) ['weight' => 89]));
    }

    public function test_moysklad_uses_second_price_as_regular_price_with_discount_price_fallback(): void
    {
        DeliveryServiceSetting::firstOrCreate(
            ['service_name' => 'moysklad'],
            ['token' => 'test-token'],
        );

        $service = app(ProductsAndVariantsSyncWithMoySkladService::class);
        $method = new \ReflectionMethod($service, 'extractPrice');

        $this->assertSame(2490.0, $method->invoke($service, [
            (object) ['value' => 199000],
            (object) ['value' => 249000],
        ]));
        $this->assertSame(1990.0, $method->invoke($service, [
            (object) ['value' => 199000],
            (object) ['value' => 0],
        ]));
    }
}
