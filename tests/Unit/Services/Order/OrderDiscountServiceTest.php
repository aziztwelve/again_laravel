<?php

namespace Tests\Unit\Services\Order;

use App\Models\Discount;
use App\Services\Order\OrderDiscountService;
use ReflectionMethod;
use Tests\TestCase;

class OrderDiscountServiceTest extends TestCase
{
    public function test_manual_percentage_discount_is_calculated_from_already_discounted_price(): void
    {
        $discount = new Discount();
        $discount->setRawAttributes([
            'type' => 'percentage',
            'value' => 10,
        ]);

        $method = new ReflectionMethod(OrderDiscountService::class, 'calculateDelta');
        $method->setAccessible(true);

        $delta = $method->invoke(app(OrderDiscountService::class), $discount, 100.0, 20.0);

        // 100 ₽ − 20 ₽ уже применённой скидки = 80 ₽; 10% от 80 ₽ = 8 ₽.
        $this->assertSame(8.0, $delta);
    }
}
