<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Order\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OrderHistorySystemChangesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_status_change_from_delivery_service_is_written_to_history(): void
    {
        Bus::fake();
        $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);

        app(OrderCreationService::class)->updateOrderStatus($order, OrderStatus::SHIPPED);

        $entry = $order->history()->latest('id')->first();
        $this->assertSame('updated', $entry->action);
        $this->assertSame("Статус заказа изменён с 'В работе' на 'Отгружен'", $entry->description);
    }

    public function test_cancellation_is_written_to_history_with_reason(): void
    {
        Bus::fake();
        $order = Order::factory()->create(['status' => OrderStatus::NEW, 'payment_status' => PaymentStatus::PENDING]);

        app(OrderCreationService::class)->cancelOrder($order, 'Автоотмена: заказ не оплачен в течение 2 часов');

        $entry = $order->history()->latest('id')->first();
        $this->assertStringContainsString("с 'Новый' на 'Отменен'", $entry->description);
        $this->assertStringContainsString('Автоотмена', $entry->description);
    }

    public function test_unchanged_status_does_not_create_an_entry(): void
    {
        Bus::fake();
        $order = Order::factory()->create(['status' => OrderStatus::NEW]);
        $before = $order->history()->count();

        app(OrderCreationService::class)->updateOrderStatus($order, OrderStatus::NEW);

        $this->assertSame($before, $order->history()->count());
    }

    public function test_payment_status_entry_has_action_and_description(): void
    {
        $order = Order::factory()->create(['payment_status' => PaymentStatus::PENDING]);

        $order->updatePaymentStatus(PaymentStatus::PAID, 'tx-1');

        $entry = $order->history()->latest('id')->first();
        $this->assertSame('updated', $entry->action);
        $this->assertStringContainsString('Оплачен', $entry->description);
    }
}
