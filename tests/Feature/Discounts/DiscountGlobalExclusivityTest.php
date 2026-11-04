<?php

namespace Tests\Feature\Discounts;

use App\Models\Discount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DiscountGlobalExclusivityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_saving_global_discount_does_not_disable_other_discounts(): void
    {
        $other = $this->discount(['discount_type' => 'specific', 'is_active' => true]);
        $this->discount(['discount_type' => 'all', 'is_active' => true]);

        $this->assertTrue($other->fresh()->is_active);
    }

    private function discount(array $attributes): Discount
    {
        return Discount::create(array_merge([
            'name' => 'Проверка глобальной скидки '.uniqid(),
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ], $attributes));
    }
}
