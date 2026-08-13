<?php

namespace Tests\Unit\Services\Delivery;

use App\Models\Order;
use App\Services\Delivery\Yandex\PayloadBuilder;
use Tests\TestCase;

class YandexPayloadBuilderTest extends TestCase
{
    public function test_order_payload_uses_internal_order_number_as_yandex_order_number(): void
    {
        $order = new Order([
            'order_number' => '34382',
            'delivery_data' => [
                'delivery_type' => 'pickup',
                'pvz' => ['id' => 'pickup-1'],
            ],
        ]);
        $order->setRelation('items', collect());

        $payload = app(PayloadBuilder::class)->order($order, [
            'platform_station_id' => 'station-1',
        ]);

        $this->assertSame('34382', $payload['info']['operator_request_id']);
    }
}
