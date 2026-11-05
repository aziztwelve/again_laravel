<?php

namespace Tests\Unit\Models;

use App\Models\CdekOrder;
use App\Models\Order;
use Tests\TestCase;

/**
 * «Номер ИМ» в СДЭК должен совпадать с номером заказа магазина: с префиксом
 * «order-» заказ не находился поиском по номеру в кабинете СДЭК.
 */
class CdekOrderExternalNumberTest extends TestCase
{
    public function test_external_number_is_the_plain_shop_order_number(): void
    {
        $order = new Order(['order_number' => '40254']);
        $order->id = 40254;

        $this->assertSame('40254', CdekOrder::externalNumberFor($order));
    }

    public function test_order_number_wins_over_id_when_they_differ(): void
    {
        $order = new Order(['order_number' => '1004']);
        $order->id = 2204;

        $this->assertSame('1004', CdekOrder::externalNumberFor($order));
    }

    public function test_falls_back_to_id_when_there_is_no_number(): void
    {
        $order = new Order();
        $order->id = 77;

        $this->assertSame('77', CdekOrder::externalNumberFor($order));
    }
}
