<?php

namespace Tests\Feature\Payment;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Виджет «Платежи» в карточке заказа админки. Нужна БД — запускать на серверной копии. */
class OrderViewPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_view_lists_gateway_payments_without_provider_data(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $order = Order::factory()->create();
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'robokassa',
            'amount' => 5,
            'currency' => 'RUB',
            'status' => Payment::STATUS_REFUNDED,
            'provider_payment_id' => '41967',
            'provider_data' => ['EMail' => 'secret@example.com', 'refund' => ['label' => 'finished']],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/view")->assertOk();
        $list = $response->json('payments') ?? $response->json('data.payments');

        $this->assertCount(1, $list);
        $this->assertSame('gateway-' . $payment->id, $list[0]['id']);
        $this->assertSame('Robokassa', $list[0]['provider_label']);
        $this->assertSame('Возвращён', $list[0]['status_label']);
        $this->assertSame('Возврат выполнен', $list[0]['refund_label']);
        $this->assertSame('41967', $list[0]['provider_payment_id']);
        $this->assertArrayNotHasKey('provider_data', $list[0]);
    }

    public function test_order_view_still_shows_migrated_legacy_payments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $order = Order::factory()->create();
        OrderPayment::create([
            'order_id' => $order->id,
            'payment_method' => 'card',
            'payment_provider' => 'cloudpayments',
            'payment_id' => 'tx-100',
            'amount' => 1500,
            'status' => 'paid',
            'processed_at' => now()->subYear(),
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/view")->assertOk();
        $list = $response->json('payments') ?? $response->json('data.payments');

        $this->assertCount(1, $list);
        $this->assertSame('CloudPayments', $list[0]['provider_label']);
        $this->assertSame('completed', $list[0]['status']);
        $this->assertSame('Оплачен', $list[0]['status_label']);
        $this->assertSame('tx-100', $list[0]['provider_payment_id']);
    }
}
