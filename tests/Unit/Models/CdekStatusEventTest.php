<?php

namespace Tests\Unit\Models;

use App\Models\CdekStatusEvent;
use Tests\TestCase;

class CdekStatusEventTest extends TestCase
{
    public function test_city_accessor_extracts_matching_status_city_from_payload(): void
    {
        $event = new CdekStatusEvent([
            'status_code' => 'CREATED',
            'status_name' => 'Создан',
            'status_at' => '2026-08-27 06:01:59',
        ]);
        $event->payload = [
            'uuid' => 'order-uuid',
            'statuses' => [
                ['code' => 'CREATED', 'name' => 'Создан', 'city' => 'Офис СДЭК', 'date_time' => '2026-08-27T06:01:59+0000'],
                ['code' => 'ACCEPTED', 'name' => 'Принят', 'city' => 'Санкт-Петербург', 'date_time' => '2026-08-27T07:15:00+0000'],
            ],
        ];

        $this->assertSame('Офис СДЭК', $event->city);
    }

    public function test_city_accessor_returns_null_when_payload_missing(): void
    {
        $event = new CdekStatusEvent(['status_code' => 'CREATED', 'status_name' => 'Создан', 'status_at' => '2026-08-27 06:01:59']);

        $this->assertNull($event->city);
    }
}
