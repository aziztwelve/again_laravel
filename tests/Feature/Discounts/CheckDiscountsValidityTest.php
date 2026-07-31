<?php

namespace Tests\Feature\Discounts;

use App\Console\Commands\CheckDiscountsValidity;
use App\Models\Discount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CheckDiscountsValidityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manually_disabled_discount_is_not_reactivated_by_scheduler(): void
    {
        $discount = $this->discount([
            'is_active' => false,
            'is_manually_disabled' => true,
        ]);

        app(CheckDiscountsValidity::class)->handle();

        $this->assertFalse($discount->fresh()->is_active);
    }

    public function test_scheduled_discount_is_activated_when_start_date_is_reached(): void
    {
        $discount = $this->discount([
            'is_active' => false,
            'is_manually_disabled' => false,
        ]);

        app(CheckDiscountsValidity::class)->handle();

        $this->assertTrue($discount->fresh()->is_active);
    }

    private function discount(array $attributes): Discount
    {
        return Discount::create(array_merge([
            'name' => 'Проверка автозапуска '.uniqid(),
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'discount_type' => 'all',
        ], $attributes));
    }
}
