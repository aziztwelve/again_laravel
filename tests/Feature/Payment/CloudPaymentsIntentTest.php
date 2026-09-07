<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CloudPaymentsIntentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.cloudpayment.enabled' => true,
            'payment.providers.cloudpayment.public_id' => 'pk_test_terminal',
            'payment.providers.cloudpayment.api_secret' => 'test-secret',
        ]);
    }

    /** Заказ с card_ru: виджет открывает карты РФ, T-Pay и СБП одной группой. */
    public function test_card_ru_intent_allows_card_tpay_and_sbp(): void
    {
        $order = $this->pendingOrder('card_ru');

        $response = $this->postJson("/api/public/orders/{$order->view_token}/cloudpayments/intent");

        $response->assertOk()->assertJsonPath('success', true);
        $payment = $response->json('payment');
        // Разрешены Card/TinkoffPay/Sbp — Restricted всё остальное.
        $this->assertSame(['SberPay', 'MirPay'], $payment['restrictedPaymentMethods']);
        $this->assertSame('pk_test_terminal', $payment['publicTerminalId']);
        $this->assertSame(2500.0, (float) $payment['amount']);
        $this->assertMatchesRegularExpression('/^payment-\d+$/', $payment['externalId']);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'cloudpayment',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    /** Коды отдельных методов (заказы до объединения опций) не теряются. */
    public function test_legacy_cloudpayments_codes_still_work(): void
    {
        $order = $this->pendingOrder('cloudpayments_sbp');

        $response = $this->postJson("/api/public/orders/{$order->view_token}/cloudpayments/intent");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(['Card', 'TinkoffPay', 'SberPay', 'MirPay'], $response->json('payment.restrictedPaymentMethods'));
    }

    /** Яндекс Пэй обрабатывает свой сервис — intent CloudPayments запрещён. */
    public function test_yandex_pay_order_is_rejected(): void
    {
        $order = $this->pendingOrder('yandex_pay');

        $response = $this->postJson("/api/public/orders/{$order->view_token}/cloudpayments/intent");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_paid_order_is_rejected(): void
    {
        $order = $this->pendingOrder('card_ru');
        $order->update(['payment_status' => PaymentStatus::PAID]);

        $response = $this->postJson("/api/public/orders/{$order->view_token}/cloudpayments/intent");

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    private function pendingOrder(string $paymentMethod): Order
    {
        return Order::factory()->create([
            'payment_method' => $paymentMethod,
            'payment_status' => PaymentStatus::PENDING,
            'total_amount' => 2500,
            'delivery_cost' => 0,
            'view_token' => bin2hex(random_bytes(16)),
        ]);
    }
}
