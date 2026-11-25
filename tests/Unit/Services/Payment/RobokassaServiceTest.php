<?php

namespace Tests\Unit\Services\Payment;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\RobokassaService;
use Tests\TestCase;

/** Подпись, чек и проверка Shp_* — без обращения к БД. */
class RobokassaServiceTest extends TestCase
{
    private RobokassaService $service;

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
            'payment.providers.robokassa.send_receipt' => false,
            'payment.providers.robokassa.receipt_vat' => 5,
        ]);

        $this->service = new RobokassaService();
    }

    public function test_result_signature_uses_password2_and_raw_out_sum(): void
    {
        $data = ['OutSum' => '2959.700000', 'InvId' => '15'];
        $data['SignatureValue'] = strtoupper(md5('2959.700000:15:pass-two'));

        $this->assertTrue($this->service->isValidResultSignature($data));
        // Пароль #1 для Result не подходит.
        $data['SignatureValue'] = md5('2959.700000:15:pass-one');
        $this->assertFalse($this->service->isValidResultSignature($data));
    }

    public function test_result_signature_includes_sorted_shp_params(): void
    {
        $data = ['OutSum' => '100.000000', 'InvId' => '7', 'Shp_b' => '2', 'Shp_a' => '1', 'Fee' => '3.5'];
        $data['SignatureValue'] = md5('100.000000:7:pass-two:Shp_a=1:Shp_b=2');

        $this->assertTrue($this->service->isValidResultSignature($data));

        $data['Shp_a'] = '9';
        $this->assertFalse($this->service->isValidResultSignature($data));
    }

    public function test_success_signature_uses_password1(): void
    {
        $data = ['OutSum' => '100.00', 'InvId' => '7'];
        $data['SignatureValue'] = md5('100.00:7:pass-one');

        $this->assertTrue($this->service->isValidSuccessSignature($data));
        $this->assertFalse($this->service->isValidResultSignature($data));
    }

    public function test_missing_or_empty_signature_is_rejected(): void
    {
        $this->assertFalse($this->service->isValidResultSignature(['OutSum' => '1', 'InvId' => '1']));
        $this->assertFalse($this->service->isValidResultSignature(['OutSum' => '1', 'InvId' => '1', 'SignatureValue' => '']));
        $this->assertFalse($this->service->isValidResultSignature([]));
    }

    public function test_test_mode_uses_test_passwords(): void
    {
        config([
            'payment.providers.robokassa.is_test' => true,
            'payment.providers.robokassa.test_password2' => 'test-two',
        ]);

        $data = ['OutSum' => '10.00', 'InvId' => '3'];
        $data['SignatureValue'] = md5('10.00:3:test-two');

        $this->assertTrue($this->service->isValidResultSignature($data));
    }

    public function test_sha256_algorithm_is_supported(): void
    {
        config(['payment.providers.robokassa.hash_algo' => 'sha256']);

        $data = ['OutSum' => '10.00', 'InvId' => '3'];
        $data['SignatureValue'] = hash('sha256', '10.00:3:pass-two');

        $this->assertTrue($this->service->isValidResultSignature($data));
    }

    public function test_request_signature_without_receipt(): void
    {
        [$order, $payment] = $this->orderAndPayment(1500.5, 42);

        $fields = $this->service->buildFields($order, $payment);

        $this->assertSame('again8', $fields['MerchantLogin']);
        $this->assertSame('1500.50', $fields['OutSum']);
        $this->assertSame('42', $fields['InvId']);
        $this->assertArrayNotHasKey('Receipt', $fields);
        $this->assertArrayNotHasKey('IncCurrLabel', $fields);
        $this->assertArrayNotHasKey('IsTest', $fields);
        $this->assertSame(
            md5('again8:1500.50:42:pass-one:Shp_pay=' . $fields['Shp_pay']),
            $fields['SignatureValue'],
        );
    }

    public function test_test_mode_adds_is_test_flag(): void
    {
        config(['payment.providers.robokassa.is_test' => true, 'payment.providers.robokassa.test_password1' => 'test-one']);
        [$order, $payment] = $this->orderAndPayment(100, 1);

        $fields = $this->service->buildFields($order, $payment);

        $this->assertSame(1, $fields['IsTest']);
        $this->assertSame(md5('again8:100.00:1:test-one:Shp_pay=' . $fields['Shp_pay']), $fields['SignatureValue']);
    }

    public function test_receipt_is_urlencoded_and_signed(): void
    {
        config(['payment.providers.robokassa.send_receipt' => true]);
        [$order, $payment] = $this->orderAndPayment(500, 9, [['Свеча "Роза"', 2, 200]], deliveryCost: 100);

        $fields = $this->service->buildFields($order, $payment);

        $receipt = json_decode(urldecode($fields['Receipt']), true);
        // Кавычки и обратные слэши в названии убираем — Robokassa не любит спецсимволы.
        $this->assertSame('Свеча Роза', $receipt['items'][0]['name']);
        $this->assertSame(400.0, (float) $receipt['items'][0]['sum']);
        $this->assertSame('vat5', $receipt['items'][0]['tax']);
        $this->assertSame('Доставка', $receipt['items'][1]['name']);
        $this->assertSame('service', $receipt['items'][1]['payment_object']);
        $this->assertSame('vat5', $receipt['items'][1]['tax']);
        $this->assertArrayNotHasKey('payments', $receipt);
        $this->assertSame(
            md5('again8:500.00:9:' . $fields['Receipt'] . ':pass-one:Shp_pay=' . $fields['Shp_pay']),
            $fields['SignatureValue'],
        );
    }

    public function test_unknown_product_vat_falls_back_to_default_rate_not_none(): void
    {
        [$order] = $this->orderAndPayment(100, 1, [['Товар', 1, 100]]);
        $order->items->first()->product->vat = 13;

        $receipt = $this->service->buildReceipt($order, 100.0);

        $this->assertSame('vat5', $receipt['items'][0]['tax']);
    }

    public function test_certificate_part_of_payment_goes_to_advance_not_negative_item(): void
    {
        [$order] = $this->orderAndPayment(700, 1, [['Товар', 1, 1000]]);

        $receipt = $this->service->buildReceipt($order, 700.0);

        $this->assertSame(1000.0, (float) $receipt['items'][0]['sum']);
        $this->assertSame([['type' => 1, 'sum' => 700.0], ['type' => 2, 'sum' => 300.0]], $receipt['payments']);
    }

    public function test_receipt_is_skipped_when_items_do_not_cover_payment(): void
    {
        [$order] = $this->orderAndPayment(900, 1, [['Товар', 1, 500]]);

        $this->assertNull($this->service->buildReceipt($order, 900.0));
    }

    public function test_shp_token_is_bound_to_payment_id(): void
    {
        [$order, $payment] = $this->orderAndPayment(100, 5);
        $token = $this->service->buildFields($order, $payment)['Shp_pay'];

        $this->assertTrue($this->service->isValidShpToken(['InvId' => '5', 'Shp_pay' => $token]));
        $this->assertFalse($this->service->isValidShpToken(['InvId' => '6', 'Shp_pay' => $token]));
        $this->assertFalse($this->service->isValidShpToken(['InvId' => '5']));
        $this->assertFalse($this->service->isValidShpToken(['InvId' => '5', 'Shp_pay' => '']));
    }

    public function test_service_is_unavailable_without_passwords_or_flag(): void
    {
        $this->assertTrue($this->service->isAvailable());

        config(['payment.providers.robokassa.password2' => '']);
        $this->assertFalse($this->service->isAvailable());

        config(['payment.providers.robokassa.password2' => 'pass-two', 'payment.providers.robokassa.enabled' => false]);
        $this->assertFalse($this->service->isAvailable());
    }

    public function test_without_allowlist_every_client_and_guest_is_offered_the_method(): void
    {
        config(['payment.providers.robokassa.allowed_emails' => []]);

        $this->assertTrue($this->service->isOfferedTo(null));
        $this->assertTrue($this->service->isOfferedTo($this->client('any@example.com')));
        $this->assertTrue($this->service->isAllowedForEmail(null));
    }

    public function test_allowlist_limits_the_method_to_listed_emails_case_insensitively(): void
    {
        config(['payment.providers.robokassa.allowed_emails' => [' AzizTwelve@Gmail.com ']]);

        $this->assertTrue($this->service->isOfferedTo($this->client('aziztwelve@gmail.com')));
        $this->assertFalse($this->service->isOfferedTo($this->client('other@example.com')));
        $this->assertFalse($this->service->isOfferedTo($this->client(null)));
        // гость (не вошёл) при закрытом тесте не видит способ
        $this->assertFalse($this->service->isOfferedTo(null));
    }

    public function test_method_is_never_offered_while_integration_is_unavailable(): void
    {
        config(['payment.providers.robokassa.allowed_emails' => [], 'payment.providers.robokassa.enabled' => false]);

        $this->assertFalse($this->service->isOfferedTo(null));
    }

    public function test_order_is_allowed_by_order_email_or_client_email(): void
    {
        config(['payment.providers.robokassa.allowed_emails' => ['aziztwelve@gmail.com']]);

        [$own] = $this->orderAndPayment(100, 1);
        $own->email = 'aziztwelve@gmail.com';
        [$foreign] = $this->orderAndPayment(100, 2);
        $foreign->email = 'other@example.com';

        $this->assertTrue($this->service->isAllowedForOrder($own));
        $this->assertFalse($this->service->isAllowedForOrder($foreign));
    }

    /** Клиент без обращения к БД (Model::fill() ходит в схему таблицы). */
    private function client(?string $email): \App\Models\Client
    {
        $client = new \App\Models\Client();
        $client->setRawAttributes(['email' => $email]);

        return $client;
    }

    /**
     * Несохранённые модели: проверяем чистую логику подписи и чека.
     *
     * @param array<int, array{0: string, 1: int, 2: float}> $lines [название, количество, цена единицы]
     * @return array{0: Order, 1: Payment}
     */
    private function orderAndPayment(float $amount, int $paymentId, array $lines = [], float $deliveryCost = 0): array
    {
        $order = new Order(['order_number' => '777', 'delivery_cost' => $deliveryCost, 'total_amount' => $amount]);
        $order->id = 1;
        $order->setRelation('client', null);

        $items = collect($lines)->map(function (array $line) {
            $product = new Product(['name' => $line[0]]);
            $item = new OrderItem(['quantity' => $line[1], 'price' => $line[2], 'is_gift' => false]);
            $item->setRelation('product', $product);

            return $item;
        });
        $order->setRelation('items', $items);

        $payment = new Payment(['amount' => $amount]);
        $payment->id = $paymentId;

        return [$order, $payment];
    }
}
