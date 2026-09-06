<?php

namespace Tests\Feature\Delivery;

use App\Models\CdekOrder;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Синхронизация статуса СДЭК пишет отправление (`shipments`). Таблица
 * требует status_id (FK) и NOT NULL получателя/адреса, поэтому раньше
 * PollCdekDeliveryStatusesJob падал на каждом заказе с
 * «Column 'status_id' cannot be null».
 */
class CdekShipmentSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_creates_shipment_with_status_and_required_recipient_fields(): void
    {
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/orders*' => Http::response(['entity' => [
                'uuid' => 'cdek-uuid-1',
                'cdek_number' => '10317088749',
                'statuses' => [[
                    'code' => 'CREATED', 'name' => 'Создан', 'city' => 'Москва',
                    'date_time' => '2026-09-06T06:01:59+0000',
                ]],
            ]]),
        ]);

        // Справочник статусов на dev-сервере может быть пустым — сервис должен
        // добрать нужный статус сам, а не падать на вставке отправления.
        ShipmentStatus::query()->where('code', ShipmentStatus::NEW)->delete();

        $method = DeliveryMethod::query()->where('code', 'cdek_courier')->firstOrFail();
        $order = Order::create([
            'order_number' => 'CDEK-'.uniqid(),
            'status' => 'new',
            'payment_status' => 'paid',
            'total_amount' => 5000,
            'delivery_method_id' => $method->id,
            'delivery_cost' => 390,
            'delivery_data' => [
                'provider' => 'cdek', 'delivery_type' => 'courier', 'tariff_code' => 137,
                'price' => 390, 'destination' => ['city_code' => 44, 'city' => 'Москва', 'address' => 'Арбат, 10'],
            ],
        ]);
        $order->address()->create([
            'city' => 'Москва',
            'address' => 'Арбат, 10',
            'recipient_first_name' => 'Иван',
            'recipient_last_name' => 'Иванов',
            'recipient_phone' => '+79990000000',
        ]);
        $cdekOrder = CdekOrder::create([
            'order_id' => $order->id,
            'external_order_number' => 'order-'.$order->id,
            'delivery_type' => 'courier',
            'tariff_code' => 137,
            'price' => 390,
            'creation_state' => 'ACCEPTED',
        ]);

        app(CdekDeliveryService::class)->sync($cdekOrder);

        $cdekOrder->refresh();
        $this->assertSame('cdek-uuid-1', $cdekOrder->cdek_uuid);
        $this->assertSame('SUCCESSFUL', $cdekOrder->creation_state);
        $this->assertNotNull($cdekOrder->shipment_id);

        $shipment = Shipment::findOrFail($cdekOrder->shipment_id);
        $this->assertSame(ShipmentStatus::NEW, $shipment->status->code);
        $this->assertSame('Иванов Иван', $shipment->recipient_name);
        $this->assertSame('+79990000000', $shipment->recipient_phone);
        $this->assertNotEmpty($shipment->shipping_address);
        $this->assertSame('Москва', $shipment->city);
        $this->assertSame('137', (string) $shipment->tariff_code);
        $this->assertSame('10317088749', $shipment->tracking_number);

        $order->refresh();
        $this->assertSame('10317088749', $order->tracking_number);
        $this->assertSame('10317088749', $order->delivery_data['cdek_number']);

        // Повторная синхронизация не создаёт второе отправление.
        app(CdekDeliveryService::class)->sync($cdekOrder->fresh());
        $this->assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
    }
}
