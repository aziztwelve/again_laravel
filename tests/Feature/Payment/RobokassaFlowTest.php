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

/** Сквозной сценарий Robokassa: intent → Result URL → Success/Fail. Нужна БД (запускать на серверной копии). */
class RobokassaFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.robokassa.enabled' => true,
            // значения из .env сервера (закрытый тест по email) не должны влиять на тесты
            'payment.providers.robokassa.allowed_emails' => [],
            'payment.providers.robokassa.merchant_login' => 'again8',
            'payment.providers.robokassa.password1' => 'pass-one',
            'payment.providers.robokassa.password2' => 'pass-two',
            'payment.providers.robokassa.is_test' => false,
            'payment.providers.robokassa.hash_algo' => 'md5',
            'payment.providers.robokassa.send_receipt' => true,
            'app.frontend_url' => 'https://shop.example',
        ]);

        // По умолчанию Robokassa считает номер счёта свободным (OpStateExt: «не удалось найти операцию»).
        $this->fakeInvoiceLookup(free: true);
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
        $this->assertSame($this->inv($payment), $response->json('fields.InvId'));
        // Номер счёта в Robokassa = номер заказа магазина (виден в кабинете Robokassa, как в админке).
        $this->assertSame((string) $order->order_number, $response->json('fields.InvId'));
        $this->assertSame((string) $order->order_number, $payment->provider_payment_id);

        $fields = $response->json('fields');
        $expected = md5(implode(':', [
            'again8', '2500.00', $this->inv($payment), $fields['Receipt'], 'pass-one', 'Shp_pay=' . $fields['Shp_pay'],
        ]));
        $this->assertSame($expected, $fields['SignatureValue']);
    }

    public function test_second_attempt_for_the_same_order_gets_a_technical_invoice_number(): void
    {
        $order = $this->pendingOrder('robokassa');

        $first = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields.InvId');
        $second = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields.InvId');

        $this->assertSame((string) $order->order_number, $first);
        $paymentId = Payment::query()->where('order_id', $order->id)->orderByDesc('id')->value('id');
        $this->assertSame((string) RobokassaService::invoiceId((int) $paymentId), $second);
        $this->assertNotSame($first, $second);
    }

    public function test_order_number_already_known_to_robokassa_falls_back_to_technical_number(): void
    {
        $this->fakeInvoiceLookup(free: false);
        $order = $this->pendingOrder('robokassa');

        $inv = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields.InvId');

        $paymentId = Payment::query()->where('order_id', $order->id)->value('id');
        $this->assertSame((string) RobokassaService::invoiceId((int) $paymentId), $inv);
    }

    public function test_failed_lookup_in_robokassa_falls_back_to_technical_number(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*OpStateExt*' => Http::response('boom', 500)]);
        $order = $this->pendingOrder('robokassa');

        $inv = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields.InvId');

        $this->assertGreaterThanOrEqual(RobokassaService::INVOICE_OFFSET, (int) $inv);
    }

    public function test_non_numeric_or_old_order_numbers_use_technical_number(): void
    {
        foreach (['AB-17', '1500', '12345678901'] as $number) {
            $order = $this->pendingOrder('robokassa');
            $order->update(['order_number' => $number]);

            $inv = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields.InvId');

            $this->assertGreaterThanOrEqual(RobokassaService::INVOICE_OFFSET, (int) $inv, $number);
        }
    }

    public function test_result_for_invoice_with_order_number_finds_the_payment_and_pays_the_order(): void
    {
        $order = $this->pendingOrder('robokassa');
        $fields = $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->json('fields');
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $outSum = '2500.000000';

        $this->post('/api/webhooks/robokassa/result', [
            'OutSum' => $outSum,
            'InvId' => $fields['InvId'],
            'SignatureValue' => strtoupper(md5($outSum . ':' . $fields['InvId'] . ':pass-two:Shp_pay=' . $fields['Shp_pay'])),
            'Shp_pay' => $fields['Shp_pay'],
        ])->assertOk()->assertSeeText('OK' . $order->order_number, false);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
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
            ->assertSeeText('OK' . $this->inv($payment), false);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_result_is_idempotent(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = $this->resultPayload($payment);

        $this->post('/api/webhooks/robokassa/result', $payload)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $this->post('/api/webhooks/robokassa/result', $payload)->assertOk()->assertSeeText('OK' . $this->inv($payment), false);

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
        $payload = ['OutSum' => '1.000000', 'InvId' => $this->inv($payment)];
        $payload['SignatureValue'] = strtoupper(md5('1.000000:' . $this->inv($payment) . ':pass-two'));

        $this->post('/api/webhooks/robokassa/result', $payload)->assertStatus(400);

        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_result_for_unknown_payment_is_rejected(): void
    {
        $payload = ['OutSum' => '100.000000', 'InvId' => (string) RobokassaService::invoiceId(99999999)];
        $payload['SignatureValue'] = md5('100.000000:' . RobokassaService::invoiceId(99999999) . ':pass-two');

        $this->post('/api/webhooks/robokassa/result', $payload)->assertStatus(400);
    }

    public function test_success_leads_to_order_page_only_with_valid_signature(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $payload = ['OutSum' => '2500.00', 'InvId' => $this->inv($payment), 'Culture' => 'ru'];
        $payload['SignatureValue'] = md5('2500.00:' . $this->inv($payment) . ':pass-one');

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
        $this->post('/api/webhooks/robokassa/fail', ['InvId' => $this->inv($payment), 'OutSum' => '2500.00'])
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

    public function test_checkout_options_follow_the_email_allowlist(): void
    {
        // Без ограничения способ предлагается всем, включая гостя.
        $this->getJson('/api/public/checkout/payment-options')->assertOk()->assertJsonPath('robokassa', true);

        config(['payment.providers.robokassa.allowed_emails' => ['allowed@example.com']]);

        $this->getJson('/api/public/checkout/payment-options')->assertOk()->assertJsonPath('robokassa', false);

        $other = \App\Models\Client::factory()->create(['email' => 'other@example.com']);
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->getJson('/api/public/checkout/payment-options')->assertOk()->assertJsonPath('robokassa', false);

        $allowed = \App\Models\Client::factory()->create(['email' => 'Allowed@Example.com']);
        \Laravel\Sanctum\Sanctum::actingAs($allowed);
        $this->getJson('/api/public/checkout/payment-options')->assertOk()->assertJsonPath('robokassa', true);
    }

    public function test_foreign_order_cannot_start_payment_or_see_the_button_on_closed_test(): void
    {
        config(['payment.providers.robokassa.allowed_emails' => ['allowed@example.com']]);

        $order = $this->pendingOrder('robokassa');
        $order->update(['email' => 'other@example.com']);

        $this->getJson("/api/public/orders/{$order->view_token}")->assertJsonPath('order.robokassa_available', false);
        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->assertStatus(422);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());

        $order->update(['email' => 'allowed@example.com']);
        $this->getJson("/api/public/orders/{$order->view_token}")->assertJsonPath('order.robokassa_available', true);
        $this->postJson("/api/public/orders/{$order->view_token}/robokassa/intent")->assertOk();
    }

    /** InvId, под которым платёж уходит в Robokassa: сохранённый номер счёта либо id + сдвиг. */
    private function inv(Payment $payment): string
    {
        return (string) ($payment->fresh()->provider_payment_id ?: RobokassaService::invoiceId((int) $payment->id));
    }

    private function fakeInvoiceLookup(bool $free): void
    {
        // Новый клиент: иначе заглушка из setUp (первая подходящая) перекрывала бы эту.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*OpStateExt*' => Http::response(
                '<OperationStateResponse><Result><Code>' . ($free ? '3' : '0') . '</Code></Result></OperationStateResponse>'
            ),
        ]);
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
            'InvId' => $this->inv($payment),
            'Fee' => '0.000000',
            'EMail' => 'buyer@example.com',
            'SignatureValue' => strtoupper(md5($outSum . ':' . $this->inv($payment) . ':pass-two')),
        ];
    }

    private function pendingOrder(string $paymentMethod): Order
    {
        $order = Order::factory()->create([
            'payment_method' => $paymentMethod,
            'payment_status' => PaymentStatus::PENDING,
            'total_amount' => 2500,
            'delivery_cost' => 0,
            'order_number' => (string) random_int(900000, 999999),
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
