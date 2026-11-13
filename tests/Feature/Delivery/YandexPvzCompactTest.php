<?php

namespace Tests\Feature\Delivery;

use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class YandexPvzCompactTest extends TestCase
{
    private function fakePoints(): Collection
    {
        return collect([[
            'id' => 'pp-1',
            'name' => 'Пункт 1',
            'type' => 'pickup_point',
            'position' => ['latitude' => 55.68, 'longitude' => 37.9],
            'address' => ['full_address' => 'Люберцы, Победы 1', 'locality' => 'Люберцы', 'house' => '1'],
            'schedule' => ['restrictions' => [['days' => [1, 2, 3], 'time_from' => ['hours' => 9]]]],
            'instruction' => 'Спросить на кассе',
            'payment_methods' => ['already_paid'],
            'operator_id' => 'op',
        ]]);
    }

    private function bbox(array $extra = []): array
    {
        return array_merge([
            'latitude_from' => 55.4, 'latitude_to' => 55.9,
            'longitude_from' => 37.4, 'longitude_to' => 38.3,
        ], $extra);
    }

    private function mockService(): void
    {
        $service = Mockery::mock(YandexDeliveryService::class);
        $service->shouldReceive('getPickupPoints')->andReturn($this->fakePoints());
        $this->app->instance(YandexDeliveryService::class, $service);
    }

    public function test_pvz_returns_full_points_by_default(): void
    {
        $this->mockService();

        $point = $this->getJson('/api/public/delivery/yandex/pvz?'.http_build_query($this->bbox()))
            ->assertOk()->json('points.0');

        $this->assertArrayHasKey('schedule', $point);
        $this->assertArrayHasKey('instruction', $point);
        $this->assertSame('Люберцы', $point['address']['locality']);
    }

    public function test_pvz_compact_returns_only_what_the_map_needs(): void
    {
        $this->mockService();

        $point = $this->getJson('/api/public/delivery/yandex/pvz?'.http_build_query($this->bbox(['compact' => 1])))
            ->assertOk()->json('points.0');

        $this->assertSame(['id', 'name', 'type', 'position', 'address'], array_keys($point));
        $this->assertSame('pp-1', $point['id']);
        $this->assertSame(55.68, $point['position']['latitude']);
        $this->assertSame(['full_address' => 'Люберцы, Победы 1'], $point['address']);
    }

    public function test_pvz_still_requires_geo_id_or_bbox_in_compact_mode(): void
    {
        $this->getJson('/api/public/delivery/yandex/pvz?compact=1')->assertStatus(422);
    }
}
