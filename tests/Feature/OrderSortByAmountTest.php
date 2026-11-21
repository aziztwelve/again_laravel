<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Order\OrderFilterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderSortByAmountTest extends TestCase
{
    use DatabaseTransactions;

    private function amounts(string $direction, array $ids): array
    {
        $request = Request::create('/orders', 'GET', ['sort_by' => 'total_amount', 'sort_order' => $direction]);

        return app(OrderFilterService::class)
            ->applySorting(Order::query()->whereIn('id', $ids), $request)
            ->pluck('total_amount')->map(fn ($v) => (float) $v)->all();
    }

    public function test_orders_are_sorted_by_amount_both_ways(): void
    {
        $ids = collect([1500, 98000, 7900, 100, 12390])
            ->map(fn ($amount) => Order::factory()->create(['total_amount' => $amount])->id)->all();

        $this->assertSame([98000.0, 12390.0, 7900.0, 1500.0, 100.0], $this->amounts('desc', $ids));
        $this->assertSame([100.0, 1500.0, 7900.0, 12390.0, 98000.0], $this->amounts('asc', $ids));
    }

    public function test_equal_amounts_keep_a_stable_order_by_id(): void
    {
        $ids = collect([5000, 5000, 5000])->map(fn ($a) => Order::factory()->create(['total_amount' => $a])->id)->all();

        $request = Request::create('/orders', 'GET', ['sort_by' => 'total_amount', 'sort_order' => 'desc']);
        $got = app(OrderFilterService::class)->applySorting(Order::query()->whereIn('id', $ids), $request)->pluck('id')->all();

        $this->assertSame(array_reverse($ids), $got);
    }
}
