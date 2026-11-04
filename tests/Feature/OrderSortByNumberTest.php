<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Order\OrderFilterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderSortByNumberTest extends TestCase
{
    use DatabaseTransactions;

    private function numbersSorted(array $params, array $scopeIds): array
    {
        $request = Request::create('/orders', 'GET', $params);
        $query = app(OrderFilterService::class)->applySorting(
            Order::query()->whereIn('id', $scopeIds),
            $request,
        );

        return $query->pluck('order_number')->all();
    }

    public function test_numbers_are_sorted_numerically_not_as_strings(): void
    {
        $numbers = ['9', '10000', '40224', '73756', '1004'];
        // Длинный общий префикс исключает пересечение с реальными номерами.
        $ids = collect($numbers)
            ->map(fn ($n) => Order::factory()->create(['order_number' => (string) (900000000 + (int) $n)])->id)
            ->all();

        $this->assertSame(
            ['900073756', '900040224', '900010000', '900001004', '900000009'],
            $this->numbersSorted(['sort_by' => 'order_number', 'sort_order' => 'desc'], $ids),
        );
        $this->assertSame(
            ['900000009', '900001004', '900010000', '900040224', '900073756'],
            $this->numbersSorted(['sort_by' => 'order_number', 'sort_order' => 'asc'], $ids),
        );
    }

    public function test_default_sort_is_still_by_created_at_desc(): void
    {
        $old = Order::factory()->create(['created_at' => now()->subDays(2)]);
        $new = Order::factory()->create(['created_at' => now()]);

        $this->assertSame(
            [$new->id, $old->id],
            app(OrderFilterService::class)
                ->applySorting(Order::query()->whereIn('id', [$old->id, $new->id]), Request::create('/orders', 'GET'))
                ->pluck('id')->all(),
        );
    }
}
