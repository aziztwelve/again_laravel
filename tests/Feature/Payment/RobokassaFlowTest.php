<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Сквозной сценарий Robokassa: intent → Result URL → Success/Fail. Нужна БД (запускать на серверной копии). */
class RobokassaFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.robokassa.enabled' => true,
            'payment.providers.robokassa.merchant_login' => 'again8',
            'payment.providers.robokassa.password1' => 'pass-one',
            'payment.providers.robokassa.password2' => 'pass-two',
            'payment.providers.robokassa.is_test' => false,
            'payment.providers.robokassa.hash_algo' => 'md5',
            'payment.providers.robokassa.send_receipt' => true,
            'app.frontend_url' => 'https://shop.example',
        ]);
    }

    public function test_intent_returns_signed_form_fields_and_creates_pending_payment(): void
    {
        $order = $this->pendingOrder('robokassa');

        $response = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_url', 'https://auth.robokassa.ru/Merchant/Index.aspx')
            ->assertJsonPath('fields.MerchantLogin', 'again8')
            ->assertJsonPath('fields.OutSum', '2500.00');

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('robokassa', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame((string) $payment->id, $response->json('fields.InvId'));

        $fields = $response->json('fields');
        $expected = md5(implode(':', [
            'again8', '2500.00', $payment->id, $fields['Receipt'], 'pass-one', 'Shp_pay=' . $fields['Shp_pay'],
        ]));
        $this->assertSame($expected, $fields['SignatureValue']);
    }

    public function test_intent_is_rejected_for_other_payment_method_paid_order_and_disabled_integration(): void
    {
        $cardOrder = $this->pendingOrder('card_ru');
        $this->postJson("/api/public/orders/{$cardOrder->view_token}/robokassa/intent")->assertStatus(422);

        $paid = $this->pendingOrder('robokassa');
        $paid->update(['payment_status' => PaymentStatus::PAID]);
        $this->postJson("/api/public/orders/{$paid->view_token}/robokassa/intent")->assertStatus(422);

        config(['payment.providers.robokassa.enabled' => false]);
        $order = $this->pendingOrder('robokassa');
        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->assertStatus(503);

        $this->assertSame(0, Payment::query()->where('provider', 'robokassa')->count());
    }

    public function test_result_marks_payment_and_order_paid_and_answers_ok(): void
    {
        [$order, $payment] = $this->orderWithPayment();

        $this->post('/api/webhooks/robokassa/result', $this->resultPayload($payment))
            ->assertOk()
            ->assertSeeText('OK' . $payment->id, false);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_result_is_idempotent(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = $this->resultPayload($payment);

        $this->post('/api/webhooks/robokassa/result', $payload)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $this->post('/api/webhooks/robokassa/result', $payload)->assertOk()->assertSeeText('OK' . $payment->id, false);

        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_result_with_bad_signature_does_not_pay(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = $this->resultPayload($payment);
        $payload['SignatureValue'] = md5('forged');

        $this->post('/api/webhooks/robokassa/result', $payload)->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_result_with_wrong_amount_does_not_pay(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = ['OutSum' => '1.000000', 'InvId' => (string) $payment->id];
        $payload['SignatureValue'] = strtoupper(md5("1.000000:{$payment->id}:pass-two"));

        $this->post('/api/webhooks/robokassa/result', $payload)->assertStatus(400);

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_result_for_unknown_payment_is_rejected(): void
    {
        $payload = ['OutSum' => '100.000000', 'InvId' => '99999999'];
        $payload['SignatureValue'] = md5('100.000000:99999999:pass-two');

        $this->post('/api/webhooks/robokassa/result', $payload)->assertStatus(400);
    }

    public function test_success_leads_to_order_page_only_with_valid_signature(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = ['OutSum' => '2500.00', 'InvId' => (string) $payment->id, 'Culture' => 'ru'];
        $payload['SignatureValue'] = md5("2500.00:{$payment->id}:pass-one");

        $this->post('/api/webhooks/robokassa/success', $payload)
            ->assertOk()
            ->assertSee("https://shop.example/orders/{$order->view_token}?payment=success", false)
            ->assertSee('window.top.location.replace', false);
        // Success оплату не подтверждает.
        $this->assertFalse($order->fresh()->isPaid());

        $payload['SignatureValue'] = md5('forged');
        $this->post('/api/webhooks/robokassa/success', $payload)
            ->assertOk()
            ->assertSee('https://shop.example/', false)
            ->assertDontSee($order->view_token, false);
    }

    public function test_fail_does_not_leak_view_token_for_guessed_inv_id(): void
    {
        [$order, $payment] = $this->orderWithPayment();

        // Только InvId — подписи нет, токен заказа не отдаём.
        $this->post('/api/webhooks/robokassa/fail', ['InvId' => (string) $payment->id, 'OutSum' => '2500.00'])
            ->assertOk()
            ->assertDontSee($order->view_token, false);

        $fields = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields');
        $this->post('/api/webhooks/robokassa/fail', ['InvId' => $fields['InvId'], 'Shp_pay' => $fields['Shp_pay']])
            ->assertOk()
            ->assertSee("https://shop.example/orders/{$order->view_token}?payment=fail", false);
    }

    public function test_public_order_exposes_robokassa_availability(): void
    {
        $order = $this->pendingOrder('robokassa');
        $this->getJson("/api/public/orders/{$order->view_token}")->assertJsonPath('order.robokassa_available', true);

        config(['payment.providers.robokassa.enabled' => false]);
        $this->getJson("/api/public/orders/{$order->view_token}")->assertJsonPath('order.robokassa_available', false);
    }

    /** @return array{0: Order, 1: Payment} */
    private function orderWithPayment(): array
    {
        $order = $this->pendingOrder('robokassa');
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'robokassa',
            'amount' => 2500,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);

        return [$order, $payment];
    }

    /** Уведомление Result URL так, как его шлёт Robokassa в проде (OutSum с 6 знаками). */
    private function resultPayload(Payment $payment): array
    {
        $outSum = number_format((float) $payment->amount, 6, '.', '');

        return [
            'OutSum' => $outSum,
            'InvId' => (string) $payment->id,
            'Fee' => '0.000000',
            'EMail' => 'buyer@example.com',
            'SignatureValue' => strtoupper(md5("{$outSum}:{$payment->id}:pass-two")),
        ];
    }

    private function pendingOrder(string $paymentMethod): Order
    {
        $order = Order::factory()->create([
            'payment_method' => $paymentMethod,
            'payment_status' => PaymentStatus::PENDING,
            'total_amount' => 2500,
            'delivery_cost' => 0,
            'view_token' => bin2hex(random_bytes(16)),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => 'Тестовый товар'])->id,
            'quantity' => 1,
            'price' => 2500,
            'discount' => 0,
        ]);

        return $order;
    }
}
