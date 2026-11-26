<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\RobokassaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Возврат через API Robokassa (Refund/Create, JWT HS256 с Password3). Нужна БД — запускать на серверной копии. */
class RobokassaRefundTest extends TestCase
{
    use DatabaseTransactions;

    private const OP_KEY = '0005F891-8CCD-434B-8455-816AFFFDBF37-0VOisWikFF';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.providers.robokassa.enabled' => true,
            'payment.providers.robokassa.allowed_emails' => [],
            'payment.providers.robokassa.merchant_login' => 'again8',
            'payment.providers.robokassa.password1' => 'pass-one',
            'payment.providers.robokassa.password2' => 'pass-two',
            'payment.providers.robokassa.password3' => 'pass-three',
            'payment.providers.robokassa.is_test' => false,
            'payment.providers.robokassa.hash_algo' => 'md5',
            'payment.providers.robokassa.send_receipt' => true,
            'payment.providers.robokassa.receipt_vat' => 5,
        ]);
    }

    public function test_full_refund_sends_signed_jwt_with_receipt_items_and_marks_payment_refunded(): void
    {
        [$order, $payment] = $this->paidOrderWithPayment(1000);
        $this->fakeRobokassa();

        $result = app(RobokassaService::class)->refund($payment);

        $this->assertSame('req-123', $result['request_id']);
        $this->assertSame('finished', $result['label']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'Refund/Create')) {
                return false;
            }

            // Тело — JWT как JSON-строка, Content-Type: application/json.
            $this->assertStringContainsString('application/json', $request->header('Content-Type')[0]);
            $jwt = json_decode($request->body(), true);
            $this->assertIsString($jwt);

            [$h, $p, $sig] = explode('.', $jwt);
            $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'pass-three', true)), '+/', '-_'), '=');
            $this->assertSame($expected, $sig, 'подпись JWT — HS256 с Password3');

            $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
            $this->assertSame(self::OP_KEY, $payload['OpKey']);
            $this->assertArrayNotHasKey('RefundSum', $payload, 'полный возврат — без RefundSum');
            $this->assertSame('Тестовый товар', $payload['InvoiceItems'][0]['Name']);
            $this->assertSame(2, $payload['InvoiceItems'][0]['Quantity']);
            $this->assertSame(500.0, (float) $payload['InvoiceItems'][0]['Cost'], 'Cost — цена за единицу');
            $this->assertSame('vat5', $payload['InvoiceItems'][0]['Tax']);
            $this->assertSame('full_payment', $payload['InvoiceItems'][0]['PaymentMethod']);

            return true;
        });

        $payment->refresh();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame('req-123', $payment->provider_data['refund']['request_id']);
    }

    public function test_opkey_is_requested_by_our_invoice_number_with_password2_signature(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        $this->fakeRobokassa();

        app(RobokassaService::class)->refund($payment);

        $inv = RobokassaService::invoiceId((int) $payment->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'OpStateExt')
            && str_contains($r->url(), "InvoiceID={$inv}")
            && str_contains($r->url(), 'Signature=' . md5("again8:{$inv}:pass-two")));
    }

    public function test_repeated_refund_does_not_call_robokassa_again(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        $this->fakeRobokassa();
        $service = app(RobokassaService::class);

        $service->refund($payment);
        // Второй вызов — по уже возвращённому платежу: берём сохранённую заявку.
        $payment->update(['status' => Payment::STATUS_COMPLETED]);
        $again = $service->refund($payment->fresh());

        $this->assertSame('req-123', $again['request_id']);
        Http::assertSentCount(3); // OpStateExt + Create + GetState, больше ничего
    }

    public function test_without_password3_refund_is_refused_with_clear_message(): void
    {
        config(['payment.providers.robokassa.password3' => '']);
        [, $payment] = $this->paidOrderWithPayment(1000);
        Http::fake();

        try {
            app(RobokassaService::class)->refund($payment);
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ROBOKASSA_PASSWORD3', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    public function test_partial_refund_is_not_supported(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Частичный возврат');

        app(RobokassaService::class)->refund($payment, 300.0);
    }

    public function test_pending_payment_cannot_be_refunded(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        $payment->update(['status' => Payment::STATUS_PENDING]);
        Http::fake();

        $this->expectException(RuntimeException::class);

        app(RobokassaService::class)->refund($payment);
    }

    public function test_unpaid_invoice_in_robokassa_stops_before_refund_request(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        Http::fake(['*OpStateExt*' => Http::response('<OperationStateResponse><Result><Code>3</Code></Result></OperationStateResponse>')]);

        try {
            app(RobokassaService::class)->refund($payment);
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не подтвердила оплату', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'Refund/Create'));
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    public function test_robokassa_rejection_keeps_payment_completed_and_shows_message(): void
    {
        [, $payment] = $this->paidOrderWithPayment(1000);
        $this->fakeRobokassa(createResponse: ['success' => false, 'message' => 'NotEnoughOperationFunds', 'requestId' => null]);

        try {
            app(RobokassaService::class)->refund($payment);
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('недостаточно средств', $e->getMessage());
        }

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    public function test_order_paid_partly_with_gift_card_is_not_refunded_automatically(): void
    {
        // В чеке позиций на 1000, а оплачено 700 (300 — сертификат): состав возврата вручную.
        [, $payment] = $this->paidOrderWithPayment(1000, paymentAmount: 700);
        Http::fake(['*OpStateExt*' => Http::response($this->opStateXml())]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не поддерживается');

        app(RobokassaService::class)->refund($payment);
    }

    public function test_without_receipt_only_money_is_refunded(): void
    {
        config(['payment.providers.robokassa.send_receipt' => false]);
        [, $payment] = $this->paidOrderWithPayment(1000);
        $this->fakeRobokassa();

        app(RobokassaService::class)->refund($payment);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'Refund/Create')) {
                return false;
            }
            $payload = json_decode(base64_decode(strtr(explode('.', json_decode($r->body(), true))[1], '-_', '+/')), true);
            $this->assertArrayNotHasKey('InvoiceItems', $payload);

            return true;
        });
    }

    /** @return array{0: Order, 1: Payment} */
    private function paidOrderWithPayment(float $itemsTotal, ?float $paymentAmount = null): array
    {
        $paymentAmount ??= $itemsTotal;

        $order = Order::factory()->create([
            'payment_method' => 'robokassa',
            'payment_status' => PaymentStatus::PAID,
            'total_amount' => $paymentAmount,
            'delivery_cost' => 0,
            'view_token' => bin2hex(random_bytes(16)),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => 'Тестовый товар'])->id,
            'quantity' => 2,
            'price' => $itemsTotal / 2,
            'discount' => 0,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'robokassa',
            'amount' => $paymentAmount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        // InvId, как его присылает Result URL.
        $payment->update(['provider_data' => ['InvId' => (string) RobokassaService::invoiceId((int) $payment->id)]]);

        return [$order, $payment->fresh()];
    }

    private function opStateXml(): string
    {
        return '<?xml version="1.0"?><OperationStateResponse><Result><Code>0</Code></Result><State><Code>100</Code></State>'
            . '<Info><OpKey>' . self::OP_KEY . '</OpKey></Info></OperationStateResponse>';
    }

    private function fakeRobokassa(?array $createResponse = null): void
    {
        Http::fake([
            '*OpStateExt*' => Http::response($this->opStateXml()),
            '*Refund/Create*' => Http::response($createResponse ?? ['success' => true, 'message' => null, 'requestId' => 'req-123']),
            '*Refund/GetState*' => Http::response(['requestId' => 'req-123', 'amount' => 1000, 'label' => 'finished']),
        ]);
    }
}
