<?php

namespace Tests\Unit;

use App\Models\PromoCode;
use PHPUnit\Framework\TestCase;

class PromoCodeFixedAllocationTest extends TestCase
{
    private function fixed(float $amount): PromoCode
    {
        $promo = new PromoCode();
        $promo->discount_type = 'fixed';
        $promo->discount_amount = $amount;

        return $promo;
    }

    private function total(array $lines, array $unitDiscounts): float
    {
        $sum = 0.0;
        foreach ($lines as $key => $line) {
            $sum += $unitDiscounts[$key] * $line['quantity'];
        }

        return round($sum, 2);
    }

    public function test_two_lines_split_the_face_value_instead_of_discounting_each_line(): void
    {
        $lines = [['base_unit' => 2490.0, 'quantity' => 1], ['base_unit' => 2490.0, 'quantity' => 1]];
        $result = $this->fixed(3000)->allocateFixedDiscount($lines);

        $this->assertSame([1500.0, 1500.0], $result);
        $this->assertSame(3000.0, $this->total($lines, $result));
    }

    public function test_quantity_does_not_multiply_the_discount(): void
    {
        $lines = [['base_unit' => 2490.0, 'quantity' => 2]];
        $result = $this->fixed(3000)->allocateFixedDiscount($lines);

        $this->assertSame([1500.0], $result);
        $this->assertSame(3000.0, $this->total($lines, $result));
    }

    public function test_discount_is_proportional_to_line_cost(): void
    {
        $lines = [['base_unit' => 1000.0, 'quantity' => 1], ['base_unit' => 3000.0, 'quantity' => 1]];
        $result = $this->fixed(2000)->allocateFixedDiscount($lines);

        $this->assertSame([500.0, 1500.0], $result);
    }

    public function test_discount_never_exceeds_the_order_total(): void
    {
        $lines = [['base_unit' => 500.0, 'quantity' => 1], ['base_unit' => 700.0, 'quantity' => 1]];
        $result = $this->fixed(3000)->allocateFixedDiscount($lines);

        $this->assertSame([500.0, 700.0], $result);
        $this->assertSame(1200.0, $this->total($lines, $result));
    }

    public function test_rounding_never_gives_more_than_the_face_value(): void
    {
        $lines = [
            ['base_unit' => 333.33, 'quantity' => 3],
            ['base_unit' => 100.01, 'quantity' => 7],
            ['base_unit' => 999.99, 'quantity' => 1],
        ];
        $result = $this->fixed(1000)->allocateFixedDiscount($lines);

        $this->assertLessThanOrEqual(1000.0, $this->total($lines, $result));
        $this->assertGreaterThan(999.0, $this->total($lines, $result));
    }

    public function test_empty_or_zero_lines_give_zero(): void
    {
        $this->assertSame([], $this->fixed(3000)->allocateFixedDiscount([]));
        $this->assertSame([0.0], $this->fixed(3000)->allocateFixedDiscount([['base_unit' => 0.0, 'quantity' => 1]]));
    }
}
