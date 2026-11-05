<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Order\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CancelUnpaidApprovedOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unpaid_approved_order_is_cancelled_after_reservation_expires_but_paid_is_not(): void
    {
        $unpaid = Order::factory()->create([
            'status' => OrderStatus::APPROVED,
            'payment_status' => PaymentStatus::PENDING,
            'reservation_expires_at' => now()->subMinute(),
        ]);
        $paid = Order::factory()->create([
            'status' => OrderStatus::APPROVED,
            'payment_status' => PaymentStatus::PAID,
            'reservation_expires_at' => now()->subMinute(),
        ]);

        $service = $this->mock(OrderCreationService::class);
        $service->shouldReceive('cancelOrder')->withArgs(fn ($order) => $order->id === $unpaid->id)->once()->andReturn(true);
        $service->shouldReceive('cancelOrder')->withArgs(fn ($order) => $order->id === $paid->id)->never();

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();
    }
}
