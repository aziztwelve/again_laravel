<?php

namespace Tests\Unit;

use App\Services\Delivery\Yandex\StatusMapper;
use PHPUnit\Framework\TestCase;

class YandexPlatformStatusMapperTest extends TestCase
{
    public function test_platform_statuses_reach_shipped_and_delivered(): void
    {
        $mapper = new StatusMapper;

        foreach (['SORTING_CENTER_LOADED', 'SORTING_CENTER_PREPARED', 'CREATED'] as $status) {
            $this->assertSame('created', $mapper->toInternal($status), $status);
        }
        foreach (['SORTING_CENTER_TRANSMITTED', 'DELIVERY_AT_START', 'DELIVERY_TRANSPORTATION', 'DELIVERY_ARRIVED_PICKUP_POINT', 'DELIVERY_TIME_INTERVALS_UPDATED'] as $status) {
            $this->assertSame('picked_up', $mapper->toInternal($status), $status);
        }
        $this->assertSame('delivered', $mapper->toInternal('DELIVERY_DELIVERED'));
        $this->assertSame('returning', $mapper->toInternal('RETURN_PREPARING'));
        $this->assertSame('cancelled', $mapper->toInternal('CANCELLED_BY_DELIVERY'));
    }

    public function test_old_statuses_still_work(): void
    {
        $mapper = new StatusMapper;

        $this->assertSame('picked_up', $mapper->toInternal('pickuped'));
        $this->assertSame('delivered', $mapper->toInternal('delivered_finish'));
        $this->assertSame('created', $mapper->toInternal('something_unknown'));
    }
}
