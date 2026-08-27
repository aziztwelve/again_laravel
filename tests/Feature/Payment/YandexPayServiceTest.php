<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\YandexPayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexPayServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.yandexpay.enabled' => true,
            'payment.providers.yandexpay.env' => 'sandbox',
            'payment.providers.yandexpay.merchant_id' => 'merchant-test',
            'payment.providers.yandexpay.api_key' => 'merchant-test',
            'payment.providers.yandexpay.api_url' => 'https://yandex-pay.test',
            'payment.providers.yandexpay.order_ttl' => 1800,
        ]);
        Event::fake([OrderPaid::class]);
    }

    public function test_intent_reuses_the_same_pending_payment_and_yandex_order(): void
    {
        $order = $this->pendingOrder();
        Http::fake([
            'https://yandex-pay.test/api/merchant/v1/orders' => Http::response([
                'data' => ['paymentUrl' => 'https://sandbox.pay.yandex.ru/pay/test-order'],
            ]),
        ]);

        $service = app(YandexPayService::class);
        $first = $service->intent($order);
        $second = $service->intent($order->fresh());

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($order): bool {
            $body = $request->data();

            return $request->url() === 'https://yandex-pay.test/api/merchant/v1/orders'
                && $body['currencyCode'] === 'RUB'
                && $body['availablePaymentMethods'] === ['CARD', 'SPLIT']
                && $body['cart']['total']['amount'] === number_format((float) $order->total_amount, 2, '.', '');
        });
    }

    public function test_captured_webhook_marks_one_payment_and_order_as_paid_idempotently(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->paymentFor($order);
        $this->fakeRemoteOrder($payment, 'CAPTURED');

        $payload = ['event' => 'ORDER_STATUS_UPDATED', 'order' => [
            'orderId' => $payment->provider_payment_id,
            'paymentStatus' => 'CAPTURED',
        ]];
        $service = app(YandexPayService::class);
        $service->processWebhook($payload);
        $service->processWebhook($payload);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_failed_webhook_keeps_order_unpaid_and_allows_a_new_attempt(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->paymentFor($order);
        $this->fakeRemoteOrder($payment, 'FAILED');

        app(YandexPayService::class)->processWebhook(['event' => 'ORDER_STATUS_UPDATED', 'order' => [
            'orderId' => $payment->provider_payment_id,
            'paymentStatus' => 'FAILED',
        ]]);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::FAILED, $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->canBePaid());
    }

    public function test_invalid_jwt_is_rejected_before_any_order_is_changed(): void
    {
        Http::fake([
            'https://sandbox.pay.yandex.ru/api/jwks' => Http::response(['keys' => []]),
        ]);

        $response = $this->call('POST', '/v1/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
        ], 'not-a-signed-jwt');

        $response->assertStatus(403)->assertJsonPath('reasonCode', 'UNAUTHORIZED');
    }

    private function pendingOrder(): Order
    {
        return Order::factory()->create([
            'payment_method' => 'yandex_pay',
            'payment_status' => PaymentStatus::PENDING,
            'total_amount' => 2500,
            'delivery_cost' => 0,
        ]);
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'provider' => 'yandexpay',
            'provider_payment_id' => 'again-'.$order->id.'-1',
            'amount' => $order->total_amount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    private function fakeRemoteOrder(Payment $payment, string $status): void
    {
        Http::fake([
            'https://yandex-pay.test/api/merchant/v1/orders/*' => Http::response([
                'data' => ['order' => [
                    'merchantId' => 'merchant-test',
                    'orderId' => $payment->provider_payment_id,
                    'orderAmount' => (string) $payment->amount,
                    'paymentStatus' => $status,
                ]],
            ]),
        ]);
    }
}
