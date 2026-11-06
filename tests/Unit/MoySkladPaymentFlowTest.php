<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\Order;
use App\Services\MoySklad\PaymentInService;
use Tests\TestCase;

class MoySkladPaymentFlowTest extends TestCase
{
    private function order(OrderStatus $status, PaymentStatus $payment, string $total = '1000.00'): Order
    {
        return (new Order)->forceFill([
            'id' => 77,
            'order_number' => '77',
            'status' => $status,
            'payment_status' => $payment,
            'total_amount' => $total,
            'moysklad_order_uuid' => 'ms-order',
        ]);
    }

    public function test_site_never_creates_shipments_in_moysklad(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertFalse(SyncOrderToMoySkladJob::shouldShip($this->order($status, PaymentStatus::PAID)), $status->value);
        }
    }

    public function test_payment_is_registered_only_for_paid_not_cancelled_orders(): void
    {
        $this->assertTrue(SyncOrderToMoySkladJob::shouldRegisterPayment($this->order(OrderStatus::NEW, PaymentStatus::PAID)));
        $this->assertFalse(SyncOrderToMoySkladJob::shouldRegisterPayment($this->order(OrderStatus::NEW, PaymentStatus::PENDING)));
        $this->assertFalse(SyncOrderToMoySkladJob::shouldRegisterPayment($this->order(OrderStatus::CANCELLED, PaymentStatus::PAID)));
    }

    public function test_payment_payload_links_to_order_and_caps_linked_sum(): void
    {
        $order = $this->order(OrderStatus::NEW, PaymentStatus::PAID, '2019.94');
        $payload = PaymentInService::buildPayload($order, 201994, 156000, ['href' => 'org'], ['href' => 'agent'], 'https://ms/api');

        $this->assertSame(201994, $payload['sum']);
        $this->assertSame(156000, $payload['operations'][0]['linkedSum']);
        $this->assertSame('customerorder', $payload['operations'][0]['meta']['type']);
        $this->assertSame('https://ms/api/entity/customerorder/ms-order', $payload['operations'][0]['meta']['href']);
        $this->assertSame('pay-77', $payload['externalCode']);
    }

    public function test_payment_payload_links_whole_sum_when_order_sum_matches(): void
    {
        $order = $this->order(OrderStatus::NEW, PaymentStatus::PAID, '7900.00');
        $payload = PaymentInService::buildPayload($order, 790000, 790000, [], [], 'https://ms/api');

        $this->assertSame(790000, $payload['operations'][0]['linkedSum']);
    }

    public function test_register_payment_is_idempotent_and_skips_unpaid_orders(): void
    {
        $service = new PaymentInService('token');

        $done = $this->order(OrderStatus::NEW, PaymentStatus::PAID)->forceFill(['moysklad_paymentin_uuid' => 'pay-uuid']);
        $this->assertSame('pay-uuid', $service->registerPayment($done));

        $this->assertNull($service->registerPayment($this->order(OrderStatus::NEW, PaymentStatus::PENDING)));
    }

    public function test_kopecks_conversion_is_exact(): void
    {
        $this->assertSame(221610, PaymentInService::toKopecks('2216.10'));
        $this->assertSame(0, PaymentInService::toKopecks(null));
    }

    public function test_delivery_position_is_a_service_line_even_for_zero_cost(): void
    {
        $line = \App\Services\MoySklad\OrderService::deliveryPosition(459.94, 'https://ms/api', 'svc-1');
        $this->assertSame('service', $line['assortment']['meta']['type']);
        $this->assertSame('https://ms/api/entity/service/svc-1', $line['assortment']['meta']['href']);
        $this->assertSame(45994, $line['price']);
        $this->assertSame(1, $line['quantity']);
        $this->assertSame(5, $line['vat']);
        $this->assertTrue($line['vatEnabled']);

        $free = \App\Services\MoySklad\OrderService::deliveryPosition(0, 'https://ms/api', 'svc-1');
        $this->assertSame(0, $free['price']);
        $this->assertSame(1, $free['quantity']);
    }
}
