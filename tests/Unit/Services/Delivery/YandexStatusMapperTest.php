<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\Yandex\StatusMapper;
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
}
