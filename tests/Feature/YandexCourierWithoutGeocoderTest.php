<?php

namespace Tests\Feature;

use App\Services\Delivery\Yandex\PayloadBuilder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class YandexCourierWithoutGeocoderTest extends TestCase
{
    private function offers(?array $destination): array
    {
        return (new PayloadBuilder)->offers(
            ['platform_station_id' => 'st-1'],
            'courier',
            [['weight' => 0.5, 'quantity' => 1]],
            null,
            $destination,
        );
    }

    public function test_courier_destination_without_coordinates_uses_address_only(): void
    {
        $location = $this->offers(['address' => 'Москва, Тверская 1'])['destination']['custom_location'];

        $this->assertSame('Москва, Тверская 1', $location['details']['full_address']);
        $this->assertArrayNotHasKey('latitude', $location);
        $this->assertArrayNotHasKey('longitude', $location);
    }

    public function test_courier_destination_with_coordinates_keeps_them(): void
    {
        $location = $this->offers(['address' => 'Москва', 'coordinates' => [37.6, 55.7]])['destination']['custom_location'];

        $this->assertSame(37.6, $location['longitude']);
        $this->assertSame(55.7, $location['latitude']);
    }

    public function test_courier_destination_requires_address_or_coordinates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->offers([]);
    }

    public function test_geocode_is_cached_and_failures_are_remembered(): void
    {
        Cache::flush();
        $service = new YandexDeliveryService(['geocoder_key' => 'k']);

        Http::fake(['geocode-maps.yandex.ru/*' => Http::sequence()
            ->push(['response' => ['GeoObjectCollection' => ['featureMember' => [['GeoObject' => ['Point' => ['pos' => '37.1 55.2']]]]]]])
            ->push('', 403)]);

        $this->assertSame([37.1, 55.2], $service->geocode('Адрес А'));
        $this->assertSame([37.1, 55.2], $service->geocode('адрес а'));
        Http::assertSentCount(1);

        $this->assertNull($service->geocode('Адрес Б'));
        $this->assertNull($service->geocode('Адрес Б'));
        Http::assertSentCount(2);
    }

    public function test_pvz_endpoint_refuses_request_without_area(): void
    {
        $this->getJson('/api/public/delivery/yandex/pvz')->assertStatus(422)->assertJsonPath('success', false);
    }
}
