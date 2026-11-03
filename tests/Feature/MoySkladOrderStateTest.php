<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DeliveryServiceSetting;
use App\Services\MoySklad\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class MoySkladOrderStateTest extends TestCase
{
    use DatabaseTransactions;

    private function state(OrderStatus $status): ?array
    {
        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'test-token']);
        Cache::put('moysklad:customerorder:states', [
            'Новый' => ['href' => 'new'],
            'Подтвержден' => ['href' => 'confirmed'],
            'Возврат товара' => ['href' => 'return'],
        ], 600);

        $method = new ReflectionMethod(OrderService::class, 'resolveStateMeta');
        $method->setAccessible(true);

        return $method->invoke(new OrderService(), $status);
    }

    public function test_approved_maps_to_confirmed_state(): void
    {
        $this->assertSame(['href' => 'confirmed'], $this->state(OrderStatus::APPROVED));
    }

    public function test_product_return_uses_its_own_state(): void
    {
        $this->assertSame(['href' => 'return'], $this->state(OrderStatus::PRODUCT_RETURN));
    }

    public function test_unknown_state_returns_null(): void
    {
        $this->assertNull($this->state(OrderStatus::SHIPPED));
    }
}
