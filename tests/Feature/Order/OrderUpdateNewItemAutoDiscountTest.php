<?php

namespace Tests\Feature\Order;

use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Заказ 41328: менеджер добавил в заказ позицию, админка прислала полную цену товара (17 360),
 * а автоскидка (−9 460) к позиции не применилась, итог остался 17 360 вместо 7 900.
 */
class OrderUpdateNewItemAutoDiscountTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(float $price, ?float $discountFixed = null): Product
    {
        $product = Product::create([
            'name' => 'AutoDiscount test '.uniqid(), 'slug' => 'ad-test-'.uniqid(), 'price' => $price,
            'currency' => 'RUB', 'is_active' => true, 'has_variants' => false, 'stock_quantity' => 100,
        ]);
        if ($discountFixed !== null) {
            $discount = Discount::create([
                'name' => 'AD '.uniqid(), 'type' => 'fixed', 'value' => $discountFixed, 'is_active' => true,
                'customer_type' => Discount::CUSTOMER_TYPE_ALL, 'discount_type' => 'specific',
            ]);
            $product->discounts()->attach($discount->id);
        }

        return $product;
    }

    private function order(array $attrs = []): Order
    {
        return Order::create($attrs + [
            'order_number' => 'AD-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending',
            'total_amount' => 0, 'delivery_cost' => 0, 'created_at' => now(),
        ]);
    }

    private function update(Order $order, array $items): Order
    {
        app(OrderUpdateService::class)->update($order, ['items' => $items]);

        return $order->fresh()->load('items');
    }

    public function test_new_item_with_full_price_gets_the_auto_discount(): void
    {
        $box = $this->product(17360, 9460);
        $order = $this->order();

        $order = $this->update($order, [['product_id' => $box->id, 'quantity' => 1, 'price' => 17360]]);

        $item = $order->items->first();
        $this->assertSame(7900.0, (float) $item->price);
        $this->assertSame(9460.0, (float) $item->discount);
        $this->assertSame(7900.0, (float) $order->total_amount);
        $this->assertSame(9460.0, (float) $order->total_items_discount);
    }

    public function test_discount_is_per_unit_for_several_pieces(): void
    {
        $box = $this->product(1000, 100);
        $order = $this->order();

        $order = $this->update($order, [['product_id' => $box->id, 'quantity' => 3, 'price' => 1000]]);

        $this->assertSame(2700.0, (float) $order->total_amount);
        $this->assertSame(300.0, (float) $order->total_items_discount);
    }

    public function test_price_set_manually_by_the_manager_is_left_alone(): void
    {
        $box = $this->product(17360, 9460);
        $order = $this->order();

        $order = $this->update($order, [['product_id' => $box->id, 'quantity' => 1, 'price' => 5000]]);

        $this->assertSame(5000.0, (float) $order->items->first()->price);
        $this->assertSame(0.0, (float) $order->items->first()->discount);
        $this->assertSame(5000.0, (float) $order->total_amount);
    }

    public function test_item_without_discount_keeps_the_submitted_price(): void
    {
        $plain = $this->product(2490);
        $order = $this->order();

        $order = $this->update($order, [['product_id' => $plain->id, 'quantity' => 2, 'price' => 2490]]);

        $this->assertSame(4980.0, (float) $order->total_amount);
        $this->assertSame(0.0, (float) $order->items->first()->discount);
    }

    public function test_existing_items_are_not_repriced_and_keep_their_discount(): void
    {
        $box = $this->product(17360, 9460);
        $other = $this->product(1000);
        $order = $this->order();
        $order->items()->create(['product_id' => $box->id, 'quantity' => 1, 'price' => 7900, 'discount' => 9460]);

        // Менеджер добавляет вторую позицию, первая приходит с прежней ценой.
        $order = $this->update($order, [
            ['product_id' => $box->id, 'quantity' => 1, 'price' => 7900],
            ['product_id' => $other->id, 'quantity' => 1, 'price' => 1000],
        ]);

        $first = $order->items->firstWhere('product_id', $box->id);
        $this->assertSame(7900.0, (float) $first->price);
        $this->assertSame(9460.0, (float) $first->discount, 'скидка прежней позиции не теряется');
        $this->assertSame(8900.0, (float) $order->total_amount);
    }

    public function test_old_item_with_expired_discount_is_not_repriced_on_edit(): void
    {
        $box = $this->product(17360, 9460);
        $order = $this->order();
        // Старый заказ: позиция куплена по полной цене (скидки тогда не было).
        $order->items()->create(['product_id' => $box->id, 'quantity' => 1, 'price' => 17360, 'discount' => 0]);

        $order = $this->update($order, [['product_id' => $box->id, 'quantity' => 2, 'price' => 17360]]);

        $this->assertSame(17360.0, (float) $order->items->first()->price, 'цену прежней позиции не пересчитываем');
        $this->assertSame(34720.0, (float) $order->total_amount);
    }
}
