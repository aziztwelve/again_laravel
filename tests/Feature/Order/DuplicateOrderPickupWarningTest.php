<?php

namespace Tests\Feature\Order;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Копия заказа без выбранного пункта выдачи (заказ 41706): менеджер получает предупреждение. */
class DuplicateOrderPickupWarningTest extends TestCase
{
    use DatabaseTransactions;

    private function source(string $methodCode, ?array $deliveryData): Order
    {
        $method = DeliveryMethod::where('code', $methodCode)->firstOrFail();

        return Order::factory()->create([
            'delivery_method_id' => $method->id,
            'delivery_data' => $deliveryData,
            'view_token' => bin2hex(random_bytes(16)),
        ]);
    }

    private function copy(Order $order): array
    {
        Bus::fake();
        Sanctum::actingAs(User::factory()->create());

        return $this->postJson("/api/orders/{$order->id}/duplicate")->assertOk()->json();
    }

    public function test_pickup_order_without_a_point_gets_a_warning(): void
    {
        $result = $this->copy($this->source('cdek_pickup', null));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('не выбран пункт выдачи', (string) $result['warning']);
    }

    public function test_pickup_order_with_a_point_has_no_warning(): void
    {
        $result = $this->copy($this->source('cdek_pickup', ['provider' => 'cdek', 'delivery_type' => 'pickup', 'pvz' => ['code' => 'MSK2337']]));

        $this->assertNull($result['warning']);
    }

    public function test_copy_keeps_delivery_data_readable_and_not_double_encoded(): void
    {
        $data = ['provider' => 'cdek', 'delivery_type' => 'pickup', 'pvz' => ['code' => 'MSK2337', 'address' => 'ул. Новорязанская, 16/11с1']];
        $result = $this->copy($this->source('cdek_pickup', $data));

        $copy = Order::findOrFail($result['order']['id']);
        $this->assertEquals($data, $copy->delivery_data);
        $this->assertSame('OBJECT', \DB::selectOne('select json_type(delivery_data) t from orders where id = ?', [$copy->id])->t);
    }

    public function test_courier_order_has_no_warning(): void
    {
        $result = $this->copy($this->source('cdek_courier', null));

        $this->assertNull($result['warning']);
    }
}
