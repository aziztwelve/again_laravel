<?php

namespace Tests\Unit\Services\Delivery;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Предпроверка данных заказа перед отправкой в СДЭК. Без неё job падал в
 * очереди, а на странице заказа после нажатия кнопки не появлялось ничего
 * (см. docs/deploy-runbook.md, запись 11).
 */
class CdekOrderReadinessTest extends TestCase
{
    private function service(array $overrides = []): CdekDeliveryService
    {
        return new CdekDeliveryService(array_replace_recursive([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
            'sender' => ['city_code' => 44, 'address' => 'Москва'],
        ], $overrides));
    }

    private function order(array $deliveryData, string $code = 'cdek_courier', bool $withPhone = true): Order
    {
        $order = new Order(['delivery_data' => $deliveryData]);
        $order->id = 71716;
        $order->setRelation('deliveryMethod', new DeliveryMethod(['code' => $code]));
        $order->setRelation('address', new OrderAddress([
            'address' => 'Тестовый адрес',
            'recipient_phone' => $withPhone ? '+79990000000' : null,
        ]));
        $order->setRelation('client', null);

        return $order;
    }

    public function test_legacy_order_without_delivery_data_reports_missing_tariff(): void
    {
        $error = $this->service()->readinessError($this->order([]));

        $this->assertNotNull($error);
        $this->assertStringContainsString('не выбран тариф', $error);
    }

    public function test_courier_order_without_destination_city_is_not_ready(): void
    {
        $error = $this->service()->readinessError($this->order([
            'delivery_type' => 'courier', 'tariff_code' => 137,
        ]));

        $this->assertNotNull($error);
        $this->assertStringContainsString('города получателя', $error);
    }

    public function test_pickup_order_without_pvz_is_not_ready(): void
    {
        $error = $this->service()->readinessError($this->order([
            'delivery_type' => 'pickup', 'tariff_code' => 136, 'destination' => ['city_code' => 44],
        ], 'cdek_pickup'));

        $this->assertSame('Не выбран ПВЗ СДЭК.', $error);
    }

    public function test_order_without_recipient_phone_is_not_ready(): void
    {
        $error = $this->service()->readinessError($this->order([
            'delivery_type' => 'courier', 'tariff_code' => 137, 'destination' => ['city_code' => 44, 'address' => 'Тест'],
        ], 'cdek_courier', withPhone: false));

        $this->assertSame('Для оформления СДЭК нужен телефон получателя.', $error);
    }

    public function test_unconfigured_sender_is_reported_first(): void
    {
        $error = $this->service(['sender' => ['city_code' => null, 'address' => null]])
            ->readinessError($this->order([]));

        $this->assertStringContainsString('CDEK_DELIVERY_SENDER_CITY_CODE', $error);
    }

    public function test_complete_courier_order_is_ready(): void
    {
        $error = $this->service()->readinessError($this->order([
            'delivery_type' => 'courier', 'tariff_code' => 137,
            'destination' => ['city_code' => 44, 'address' => 'Тест'],
        ]));

        $this->assertNull($error);
    }

    public function test_create_external_order_reports_readiness_error_instead_of_calling_api(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service()->createExternalOrder(
                $this->order([]),
                new \App\Models\CdekOrder(['order_id' => 71716, 'external_order_number' => 'order-71716']),
            );
        } finally {
            Http::assertNothingSent();
        }
    }
}
