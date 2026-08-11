<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\Yandex\StatusMapper;
use App\Services\Delivery\Yandex\CustomerStatusMapper;
use PHPUnit\Framework\TestCase;

class YandexStatusMapperTest extends TestCase
{
    public function test_it_normalizes_yandex_claim_statuses(): void
    {
        $mapper = new StatusMapper();

        self::assertSame('courier_assigned', $mapper->toInternal('performer_found'));
        self::assertSame('delivered', $mapper->toInternal('delivered_finish'));
        self::assertSame('cancelled_paid', $mapper->toInternal('cancelled_with_payment'));
        self::assertSame('created', $mapper->toInternal('unknown_status'));
    }

    public function test_it_maps_only_known_customer_statuses(): void
    {
        $mapper = new CustomerStatusMapper();

        self::assertSame('delivery_created', $mapper->toCustomer('accepted'));
        self::assertSame('handed_over', $mapper->toCustomer('picked_up'));
        self::assertSame('ready_for_pickup', $mapper->toCustomer('ready_for_pickup'));
        self::assertSame('delivered', $mapper->toCustomer('delivered'));
        self::assertNull($mapper->toCustomer('unknown_status'));
    }
}
