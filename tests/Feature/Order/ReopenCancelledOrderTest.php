<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Client;
use App\Jobs\RestoreOrderStockInMoySkladJob;
use App\Jobs\ReturnOrderStockToMoySkladJob;
use App\Models\DeliveryServiceSetting;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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

    public function test_admin_can_reopen_through_order_card_api_for_each_payment_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
        foreach (PaymentStatus::cases() as $payment) {
            $deadline = now()->subDay();
            $order = Order::factory()->create(['status' => OrderStatus::CANCELLED, 'payment_status' => $payment, 'reservation_expires_at' => $deadline]);
            $this->putJson('/api/orders/'.$order->id, ['status' => 'new'])->assertOk();
            $order->refresh();
            $this->assertSame(OrderStatus::NEW, $order->status);
            $this->assertSame($payment, $order->payment_status);
            $this->assertTrue($order->reservation_expires_at->equalTo($payment === PaymentStatus::PAID ? $deadline : now()->addHours(2)));
        }
    }

    public function test_api_rejects_other_edits_and_customers(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::CANCELLED]);
        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/orders/'.$order->id, ['status' => 'processing'])->assertStatus(422);
        $this->putJson('/api/orders/'.$order->id, ['status' => 'new', 'notes' => 'test'])->assertStatus(422);
        Sanctum::actingAs(Client::factory()->create());
        $this->putJson('/api/orders/'.$order->id, ['status' => 'new'])->assertForbidden();
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_reopening_existing_shipment_dispatches_restore_job(): void
    {
        Bus::fake();
        Sanctum::actingAs(User::factory()->create());
        $order = Order::factory()->create(['status' => OrderStatus::CANCELLED, 'moysklad_demand_uuid' => 'test-demand']);
        $this->putJson('/api/orders/'.$order->id, ['status' => 'new'])->assertOk();
        Bus::assertDispatched(RestoreOrderStockInMoySkladJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_stock_jobs_only_change_shipment_for_current_order_status(): void
    {
        DeliveryServiceSetting::query()->updateOrCreate(['service_name' => 'moysklad'], ['token' => 'test-token']);
        Http::fake(['api.moysklad.ru/*' => Http::response([], 200)]);
        $order = Order::factory()->create(['status' => OrderStatus::CANCELLED, 'moysklad_demand_uuid' => 'test-demand']);
        (new RestoreOrderStockInMoySkladJob($order->id))->handle();
        Http::assertNothingSent();
        (new ReturnOrderStockToMoySkladJob($order->id))->handle();
        Http::assertSent(fn ($request) => $request->data()['applicable'] === false);
        $order->update(['status' => OrderStatus::NEW]);
        (new ReturnOrderStockToMoySkladJob($order->id))->handle();
        (new RestoreOrderStockInMoySkladJob($order->id))->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->data()['applicable'] === true);
    }
}
