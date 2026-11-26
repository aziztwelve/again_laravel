<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\RobokassaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Сверка с Robokassa, Fail URL, срок счёта, команда robokassa:reconcile, подписи для админки. Нужна БД. */
class RobokassaSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.robokassa.enabled' => true,
            'payment.providers.robokassa.allowed_emails' => [],
            'payment.providers.robokassa.merchant_login' => 'again8',
            'payment.providers.robokassa.password1' => 'pass-one',
            'payment.providers.robokassa.password2' => 'pass-two',
            'payment.providers.robokassa.is_test' => false,
            'payment.providers.robokassa.send_receipt' => true,
            'app.frontend_url' => 'https://shop.example',
        ]);
    }

    // ---------- сверка (OpStateExt) ----------

    public function test_status_endpoint_pays_the_order_when_robokassa_reports_payment_but_result_never_came(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();
        $this->fakeOpState(100, '5.000000');

        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/status")
            ->assertOk()
            ->assertJsonPath('payment_status.value', 'paid');

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_status_endpoint_does_not_pay_when_amount_differs(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();
        $this->fakeOpState(100, '1.000000');

        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/status")->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_status_endpoint_keeps_order_unpaid_while_robokassa_has_no_payment(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();
        $this->fakeOpState(5, '5.000000'); // счёт создан, не оплачен

        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/status")
            ->assertOk()->assertJsonPath('payment_status.value', 'pending');

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_status_endpoint_survives_robokassa_outage_and_rejects_foreign_orders(): void
    {
        [$order] = $this->orderWithPendingPayment();
        Http::fake(['*OpStateExt*' => Http::response('boom', 500)]);

        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/status")->assertOk();

        $other = $this->order('card_ru');
        $this->postJson("/api/public/orders/{$other->view_token}/robokassa/status")->assertStatus(422);
    }

    public function test_sync_marks_refund_made_in_the_cabinet(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();
        $payment->update(['status' => Payment::STATUS_COMPLETED]);
        $order->update(['payment_status' => PaymentStatus::PAID]);
        $this->fakeOpState(60, '5.000000');

        $result = app(RobokassaService::class)->syncPayment($payment->fresh());

        $this->assertSame('refunded', $result);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame('finished', $payment->provider_data['refund']['label']);
        $this->assertSame('Возврат выполнен', $payment->refund_label);
        // Заказ при сверке не трогаем — статус меняет менеджер.
        $this->assertTrue($order->fresh()->isPaid());
    }

    // ---------- Fail URL ----------

    public function test_fail_url_marks_attempt_failed_and_keeps_order_payable(): void
    {
        $this->freeInvoiceLookup();
        $order = $this->order('robokassa');
        $fields = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields');
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        $this->post('/api/webhooks/robokassa/fail', ['InvId' => $fields['InvId'], 'Shp_pay' => $fields['Shp_pay']])->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNotNull($payment->error_message);
        $this->assertSame(PaymentStatus::FAILED, $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->canBePaid(), 'после отказа заказ можно оплатить снова');
    }

    public function test_fail_url_without_valid_token_changes_nothing(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();

        $this->post('/api/webhooks/robokassa/fail', ['InvId' => $payment->provider_payment_id])->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PENDING, $order->fresh()->payment_status);
    }

    public function test_fail_url_never_touches_an_already_paid_payment(): void
    {
        $this->freeInvoiceLookup();
        $order = $this->order('robokassa');
        $fields = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields');
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $payment->update(['status' => Payment::STATUS_COMPLETED]);
        $order->update(['payment_status' => PaymentStatus::PAID]);

        $this->post('/api/webhooks/robokassa/fail', ['InvId' => $fields['InvId'], 'Shp_pay' => $fields['Shp_pay']])->assertOk();

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_payment_after_a_failed_attempt_still_pays_the_order(): void
    {
        [$order, $payment] = $this->orderWithPendingPayment();
        app(RobokassaService::class)->markFailed($payment);
        $this->fakeOpState(100, '5.000000');

        app(RobokassaService::class)->syncPayment($payment->fresh());

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    // ---------- срок счёта ----------

    public function test_invoice_expires_with_the_order_reservation_in_moscow_time(): void
    {
        Http::fake(['*OpStateExt*' => Http::response('<R><Result><Code>3</Code></Result></R>')]);
        $order = $this->order('robokassa');
        $expires = now()->addHours(2);
        $order->update(['reservation_expires_at' => $expires]);

        $fields = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields');

        $this->assertSame($expires->copy()->setTimezone('Europe/Moscow')->format('Y-m-d\TH:i'), $fields['ExpirationDate']);
    }

    public function test_no_expiration_when_reservation_is_missing_or_already_over(): void
    {
        Http::fake(['*OpStateExt*' => Http::response('<R><Result><Code>3</Code></Result></R>')]);

        $order = $this->order('robokassa');
        $this->assertArrayNotHasKey('ExpirationDate', $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields'));

        $order->update(['reservation_expires_at' => now()->subMinute()]);
        $this->assertArrayNotHasKey('ExpirationDate', $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields'));
    }

    // ---------- команда robokassa:reconcile ----------

    public function test_reconcile_command_finds_missed_payment_and_refreshes_refund_state(): void
    {
        [$orderA, $missed] = $this->orderWithPendingPayment();
        Payment::query()->whereKey($missed->id)->update(['created_at' => now()->subMinutes(10)]);

        [, $refunded] = $this->orderWithPendingPayment();
        $refunded->update([
            'status' => Payment::STATUS_REFUNDED,
            'provider_data' => ['refund' => ['request_id' => 'req-1', 'label' => 'processing']],
        ]);

        Http::fake([
            '*OpStateExt*' => Http::response($this->opStateXml(100, '5.000000')),
            '*Refund/GetState*' => Http::response(['requestId' => 'req-1', 'amount' => 5, 'label' => 'finished']),
        ]);

        $this->artisan('robokassa:reconcile')->assertSuccessful();

        $this->assertSame(Payment::STATUS_COMPLETED, $missed->fresh()->status);
        $this->assertTrue($orderA->fresh()->isPaid());
        $this->assertSame('finished', $refunded->fresh()->provider_data['refund']['label']);
    }

    public function test_reconcile_skips_fresh_attempts_and_does_nothing_when_integration_is_off(): void
    {
        [, $fresh] = $this->orderWithPendingPayment(); // создан только что — покупатель ещё может платить
        Http::fake(['*OpStateExt*' => Http::response($this->opStateXml(100, '5.000000'))]);

        $this->artisan('robokassa:reconcile')->assertSuccessful();
        $this->assertSame(Payment::STATUS_PENDING, $fresh->fresh()->status);

        config(['payment.providers.robokassa.enabled' => false]);
        Payment::query()->whereKey($fresh->id)->update(['created_at' => now()->subMinutes(10)]);
        $this->artisan('robokassa:reconcile')->assertSuccessful();
        $this->assertSame(Payment::STATUS_PENDING, $fresh->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_canceled_refund_returns_payment_to_completed(): void
    {
        [, $payment] = $this->orderWithPendingPayment();
        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
            'provider_data' => ['refund' => ['request_id' => 'req-9', 'label' => 'processing']],
        ]);
        Http::fake(['*Refund/GetState*' => Http::response(['requestId' => 'req-9', 'label' => 'canceled'])]);

        $label = app(RobokassaService::class)->syncRefund($payment->fresh());

        $this->assertSame('canceled', $label);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    // ---------- подписи для админки ----------

    public function test_payment_exposes_labels_for_the_admin_widget(): void
    {
        $payment = new Payment(['provider' => 'robokassa', 'status' => Payment::STATUS_COMPLETED]);
        $payment->setRawAttributes(['provider' => 'robokassa', 'status' => 'completed', 'provider_data' => null]);

        $this->assertSame('Robokassa', $payment->provider_label);
        $this->assertSame('Оплачен', $payment->status_label);
        $this->assertNull($payment->refund_label);

        foreach ([['cloudpayment', 'CloudPayments'], ['yandexpay', 'Яндекс Пэй'], ['yookassa', 'ЮKassa']] as [$code, $label]) {
            $payment->setRawAttributes(['provider' => $code, 'status' => 'refunded']);
            $this->assertSame($label, $payment->provider_label);
            $this->assertSame('Возвращён', $payment->status_label);
            $this->assertSame('Возврат выполнен', $payment->refund_label);
        }

        $this->assertArrayHasKey('provider_label', $payment->toArray());
    }

    // ---------- помощники ----------

    private function order(string $paymentMethod): Order
    {
        $order = Order::factory()->create([
            'payment_method' => $paymentMethod,
            'payment_status' => PaymentStatus::PENDING,
            'total_amount' => 5,
            'delivery_cost' => 0,
            'order_number' => (string) random_int(900000, 999999),
            'view_token' => bin2hex(random_bytes(16)),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => 'Тестовый товар'])->id,
            'quantity' => 1,
            'price' => 5,
            'discount' => 0,
        ]);

        return $order;
    }

    /** @return array{0: Order, 1: Payment} заказ и «ушедшая в Robokassa» попытка оплаты */
    private function orderWithPendingPayment(): array
    {
        $order = $this->order('robokassa');
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'robokassa',
            'amount' => 5,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);
        $payment->update(['provider_payment_id' => (string) $order->order_number]);

        return [$order, $payment->fresh()];
    }

    private function opStateXml(int $state, string $outSum): string
    {
        return '<?xml version="1.0"?><OperationStateResponse><Result><Code>0</Code></Result><State><Code>' . $state . '</Code></State>'
            . '<Info><IncCurrLabel>BankCardPSR</IncCurrLabel><OutSum>' . $outSum . '</OutSum><OpKey>X-1</OpKey></Info></OperationStateResponse>';
    }

    /** Robokassa считает любой номер счёта свободным (OpStateExt: «не найдено»). */
    private function freeInvoiceLookup(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*OpStateExt*' => Http::response('<R><Result><Code>3</Code></Result></R>')]);
    }

    private function fakeOpState(int $state, string $outSum): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*OpStateExt*' => Http::response($this->opStateXml($state, $outSum))]);
    }
}
