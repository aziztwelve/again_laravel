<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReopenCancelledOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cancelled_order_can_only_be_reopened(): void
    {
        $service = app(OrderUpdateService::class);
        $order = Order::factory()->create(['status' => OrderStatus::CANCELLED]);

        $this->assertTrue($service->canUpdate($order, ['status' => 'new']));
        $this->assertFalse($service->canUpdate($order, ['status' => 'processing']));
        $this->assertFalse($service->canUpdate($order, ['status' => 'new', 'notes' => 'changed']));
        $this->assertFalse($service->canUpdate($order, ['notes' => 'changed']));
    }

    public function test_reopened_unpaid_order_receives_a_new_reservation_deadline(): void
    {
        config(['abandoned_order.reservation_hours' => 2]);
        $this->travelTo(now()->startOfSecond());
        $order = Order::factory()->create([
            'status' => OrderStatus::CANCELLED,
            'payment_status' => PaymentStatus::FAILED,
            'reservation_expires_at' => now()->subDay(),
        ]);

        $this->assertTrue(app(OrderUpdateService::class)->update($order, ['status' => 'new']));
        $order->refresh();
        $this->assertSame(OrderStatus::NEW, $order->status);
        $this->assertTrue($order->reservation_expires_at->equalTo(now()->addHours(2)));
    }

    public function test_delivered_order_remains_locked(): void
    {
        $order = Order::factory()->make(['status' => OrderStatus::DELIVERED]);
        $this->assertFalse(app(OrderUpdateService::class)->canUpdate($order, ['status' => 'new']));
    }
}
