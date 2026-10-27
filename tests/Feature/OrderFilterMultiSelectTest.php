<?php

namespace Tests\Feature;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Services\Order\OrderFilterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderFilterMultiSelectTest extends TestCase
{
    use DatabaseTransactions;

    private function method(): DeliveryMethod
    {
        return DeliveryMethod::create(['name' => 'test-'.Str::uuid(), 'code' => 'test-'.Str::uuid(), 'is_active' => true]);
    }

    /**
     * Возвращает id заказов, которые проходят фильтр, среди заданных в тесте.
     */
    private function matchedIds(array $params, array $scopeIds): array
    {
        $query = app(OrderFilterService::class)->applyFilters(
            Order::query()->whereIn('id', $scopeIds),
            Request::create('/orders', 'GET', $params),
        );

        return $query->pluck('id')->sort()->values()->all();
    }

    public function test_payment_status_accepts_multiple_values(): void
    {
        $paid = Order::factory()->create(['payment_status' => 'paid']);
        $pending = Order::factory()->create(['payment_status' => 'pending']);
        $failed = Order::factory()->create(['payment_status' => 'failed']);
        $scope = [$paid->id, $pending->id, $failed->id];

        $this->assertSame(
            collect([$paid->id, $pending->id])->sort()->values()->all(),
            $this->matchedIds(['payment_status' => ['paid', 'pending']], $scope),
        );
    }

    public function test_payment_status_still_accepts_single_string(): void
    {
        $paid = Order::factory()->create(['payment_status' => 'paid']);
        $pending = Order::factory()->create(['payment_status' => 'pending']);
        $scope = [$paid->id, $pending->id];

        $this->assertSame([$paid->id], $this->matchedIds(['payment_status' => 'paid'], $scope));
    }

    public function test_invalid_payment_status_does_not_empty_the_list(): void
    {
        $paid = Order::factory()->create(['payment_status' => 'paid']);
        $pending = Order::factory()->create(['payment_status' => 'pending']);
        $scope = [$paid->id, $pending->id];

        $this->assertSame(
            collect([$paid->id, $pending->id])->sort()->values()->all(),
            $this->matchedIds(['payment_status' => ['bogus']], $scope),
        );
    }

    public function test_delivery_method_accepts_multiple_ids(): void
    {
        $a = $this->method();
        $b = $this->method();
        $c = $this->method();
        $oa = Order::factory()->create(['delivery_method_id' => $a->id]);
        $ob = Order::factory()->create(['delivery_method_id' => $b->id]);
        $oc = Order::factory()->create(['delivery_method_id' => $c->id]);
        $scope = [$oa->id, $ob->id, $oc->id];

        $this->assertSame(
            collect([$oa->id, $ob->id])->sort()->values()->all(),
            $this->matchedIds(['delivery_method_id' => [$a->id, $b->id]], $scope),
        );
    }

    public function test_delivery_method_still_accepts_single_id(): void
    {
        $a = $this->method();
        $b = $this->method();
        $oa = Order::factory()->create(['delivery_method_id' => $a->id]);
        $ob = Order::factory()->create(['delivery_method_id' => $b->id]);

        $this->assertSame([$oa->id], $this->matchedIds(['delivery_method_id' => (string) $a->id], [$oa->id, $ob->id]));
    }
}
