<?php

namespace Tests\Unit;

use App\Http\Resources\OrderHistoryResource;
use App\Models\Image;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderHistoryResourceTest extends TestCase
{
    private function order(): Order
    {
        $order = new Order([
            'order_number' => '456789', 'status' => 'delivered',
            'total_amount' => 7300, 'discount_amount' => 1000, 'delivery_cost' => 300,
            'seller_comment' => 'Internal note', 'delivery_address' => 'Legacy address',
        ]);
        $order->setRelation('items', collect());
        foreach (['address', 'deliveryMethod', 'deliveryTarget', 'yandexOrder', 'cdekOrder'] as $relation) {
            $order->setRelation($relation, null);
        }
        return $order;
    }

    public function test_receipt_counts_units_and_does_not_double_count_discounts(): void
    {
        $order = $this->order();
        $product = new Product(['name' => 'Box Teens', 'slug' => 'box-teens']);
        $product->setRelation('images', collect([
            new Image(['path' => 'other.jpg', 'is_main' => false]),
            new Image(['path' => 'main.jpg', 'is_main' => true]),
        ]));
        $item = new OrderItem(['quantity' => 2, 'price' => 3500, 'discount' => 500]);
        $item->setRelation('product', $product);
        $item->setRelation('variant', null);
        $item->setRelation('color', null);
        $order->setRelation('items', collect([$item]));
        $data = (new OrderHistoryResource($order))->resolve(Request::create('/'));
        $this->assertSame(2, $data['quantity']);
        $this->assertEquals(8000, $data['subtotal']);
        $this->assertEquals(1000, $data['discount_amount']);
        $this->assertEquals(7300, $data['total_amount']);
        $this->assertEquals(7000, $data['items'][0]['total']);
        $this->assertSame('main.jpg', $data['items'][0]['image_path']);
        $this->assertSame('Доставлен', $data['status_label']);
        $this->assertArrayNotHasKey('seller_comment', $data);
    }

    public function test_legacy_items_and_loaded_missing_address_need_no_database_query(): void
    {
        $order = $this->order();
        $item = new OrderItem(['legacy_name' => 'Archived Box', 'quantity' => 1, 'price' => 20]);
        foreach (['product', 'variant', 'color'] as $relation) $item->setRelation($relation, null);
        $order->setRelation('items', collect([$item]));
        $data = (new OrderHistoryResource($order))->resolve(Request::create('/'));
        $this->assertSame('Legacy address', $data['delivery_address']);
        $this->assertSame('Archived Box', $data['items'][0]['name']);
        $this->assertNull($data['items'][0]['product_slug']);
        $this->assertNull($data['items'][0]['image_path']);
    }

    public function test_delivery_address_includes_apartment_and_null_cost_is_not_free(): void
    {
        $order = $this->order();
        $order->delivery_cost = null;
        $order->setRelation('address', new OrderAddress([
            'city' => 'Москва', 'address' => 'ул. Ленина, 21', 'entrance' => '2',
        ]));
        $data = (new OrderHistoryResource($order))->resolve(Request::create('/'));
        $this->assertSame('Москва, ул. Ленина, 21, кв. 2', $data['delivery_address']);
        $this->assertNull($data['delivery_cost']);
    }
}
