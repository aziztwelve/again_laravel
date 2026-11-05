<?php

namespace Tests\Unit\Services\Order;

use App\Services\Order\OrderFilterService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Номер заказа в поиске часто копируют из письма или CloudPayments с «№».
 */
class OrderFilterOrderNumberTest extends TestCase
{
    private function normalize(string $value): string
    {
        $method = new ReflectionMethod(OrderFilterService::class, 'normalizeOrderNumber');
        $method->setAccessible(true);

        return $method->invoke(app(OrderFilterService::class), $value);
    }

    public function test_sign_and_spaces_are_stripped(): void
    {
        $this->assertSame('40249', $this->normalize('40249'));
        $this->assertSame('40249', $this->normalize(' 40249 '));
        $this->assertSame('40249', $this->normalize('№40249'));
        $this->assertSame('40249', $this->normalize('№ 40249'));
        $this->assertSame('40249', $this->normalize('#40249'));
    }

    public function test_non_numeric_numbers_keep_their_text(): void
    {
        $this->assertSame('TEST-ABC', $this->normalize(' TEST-ABC'));
        $this->assertSame('MS-TEST-1', $this->normalize('№MS-TEST-1'));
    }

    public function test_number_sign_in_the_middle_is_not_touched(): void
    {
        $this->assertSame('A№1', $this->normalize('A№1'));
    }
}
