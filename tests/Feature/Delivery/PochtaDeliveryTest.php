<?php

namespace Tests\Feature\Delivery;

use App\Services\Delivery\PochtaDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PochtaDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.pochta.enabled' => true,
            'services.pochta.token' => 'test-token',
            'services.pochta.login' => 'user@example.com',
            'services.pochta.password' => 'secret',
            'services.pochta.index_from' => null,
        ]);
    }

    private function items(int $grams = 100, int $quantity = 2, float $price = 1500): array
    {
        return [['name' => 'Тест', 'weight' => $grams, 'length' => 20, 'width' => 10, 'height' => 5, 'price' => $price, 'quantity' => $quantity]];
    }

    private function tariffResponse(int $rate = 35900, int $vat = 7898, array $time = ['max-days' => 7]): array
    {
        return ['total-rate' => $rate, 'total-vat' => $vat, 'delivery-time' => $time];
    }

    public function test_office_tariff_is_priced_with_vat_and_declared_value(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response($this->tariffResponse())]);

        $tariffs = app(PochtaDeliveryService::class)->calculateTariffs('russian_post_office', ['index' => '101000'], $this->items());

        $this->assertCount(1, $tariffs);
        $this->assertSame('ONLINE_PARCEL', $tariffs[0]['tariff_code']);
        $this->assertSame(437.98, $tariffs[0]['price']);
        $this->assertSame(['min' => 7, 'max' => 7], $tariffs[0]['period']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'AccessToken test-token')
                && $request->hasHeader('X-User-Authorization', 'Basic '.base64_encode('user@example.com:secret'))
                && $body['index-to'] === '101000'
                && $body['mail-type'] === 'ONLINE_PARCEL'
                && $body['mail-category'] === 'WITH_DECLARED_VALUE'
                && $body['declared-value'] === 300000
                && $body['mass'] === 200
                && ! isset($body['index-from']);
        });
    }

    public function test_zero_price_means_no_tariff(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response($this->tariffResponse(0, 0))]);

        $this->expectException(InvalidArgumentException::class);

        app(PochtaDeliveryService::class)->calculateTariffs('russian_post_office', ['index' => '918332'], $this->items());
    }

    public function test_office_method_requires_a_selected_office(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Выберите отделение');

        app(PochtaDeliveryService::class)->calculateTariffs('russian_post_office', ['city' => 'Москва'], $this->items());
    }

    public function test_courier_gets_index_from_address_normalization(): void
    {
        Http::fake([
            '*/1.0/clean/address' => Http::response([['id' => 'a1', 'index' => '125009', 'quality-code' => 'GOOD']]),
            '*/1.0/tariff' => Http::response($this->tariffResponse(43500, 9570)),
        ]);

        $tariffs = app(PochtaDeliveryService::class)->calculateTariffs('russian_post_courier', ['city' => 'Москва', 'address' => 'Тверская 7'], $this->items());

        $this->assertSame(530.7, $tariffs[0]['price']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1.0/tariff')
            && $request['index-to'] === '125009' && $request['courier'] === true && $request['mail-type'] === 'ONLINE_COURIER');
    }

    public function test_courier_with_unrecognised_address_is_rejected(): void
    {
        Http::fake(['*/1.0/clean/address' => Http::response([['id' => 'a1', 'index' => '100000', 'quality-code' => 'UNDEF_05']])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('почтовый индекс');

        app(PochtaDeliveryService::class)->calculateTariffs('russian_post_courier', ['city' => 'Москва', 'address' => 'куда-то'], $this->items());
    }

    public function test_international_uses_country_code_and_skips_small_packet_over_two_kilos(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response($this->tariffResponse(300000, 0, ['max-days' => 36, 'min-days' => 31]))]);

        $tariffs = app(PochtaDeliveryService::class)
            ->calculateTariffs('international_pickup', ['country_code' => 'DE'], $this->items(3000, 1));

        $this->assertSame(['POSTAL_PARCEL'], array_column($tariffs, 'tariff_code'));
        $this->assertSame(['min' => 31, 'max' => 36], $tariffs[0]['period']);
        Http::assertSent(fn (Request $request) => $request['mail-direct'] === 276
            && $request['entries-type'] === 'SALE_OF_GOODS' && ! isset($request['declared-value']) && ! isset($request['index-to']));
    }

    public function test_international_pickup_offers_cheapest_first(): void
    {
        Http::fake(function (Request $request) {
            return Http::response($request['mail-type'] === 'SMALL_PACKET'
                ? $this->tariffResponse(156160, 0, ['max-days' => 34, 'min-days' => 29])
                : $this->tariffResponse(359534, 0, ['max-days' => 36, 'min-days' => 31]));
        });

        $tariffs = app(PochtaDeliveryService::class)->calculateTariffs('international_pickup', ['country_code' => 'DE'], $this->items(100, 1));

        $this->assertSame(['SMALL_PACKET', 'POSTAL_PARCEL'], array_column($tariffs, 'tariff_code'));
    }

    public function test_domestic_method_rejects_foreign_country_and_international_rejects_russia(): void
    {
        Http::fake();
        $service = app(PochtaDeliveryService::class);

        try {
            $service->calculateTariffs('russian_post_office', ['index' => '101000', 'country_code' => 'DE'], $this->items());
            $this->fail('Домашний способ принял чужую страну');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $service->calculateTariffs('international_courier', ['country_code' => 'RU'], $this->items());
    }

    public function test_offices_keep_only_open_post_offices_and_format_hours(): void
    {
        $hours = array_map(fn ($day) => ['weekday-id' => $day, 'begin-worktime' => '09:00:00.000', 'end-worktime' => '21:00:00.000'], [1, 2, 3, 4, 5]);
        $hours[] = ['weekday-id' => 6, 'begin-worktime' => '10:00:00.000', 'end-worktime' => '18:00:00.000'];
        $office = fn (string $code, string $type, array $extra = []) => $extra + [
            'postal-code' => $code, 'type-code' => $type, 'address-source' => 'Никольская ул, 7', 'latitude' => 55.75, 'longitude' => 37.62,
            'distance' => 224.5, 'is-closed' => false, 'working-hours' => $hours,
        ];
        Http::fake(['*/postoffice/1.0/nearby*' => Http::response([
            $office('109012', 'ГОПС'),
            $office('918332', 'ПОЧТОМАТ'),
            $office('983308', 'ППВЗ'),
            $office('109013', 'СОПС', ['is-closed' => true]),
        ])]);

        $offices = app(PochtaDeliveryService::class)->offices('Москва', 55.7558, 37.6173);

        $this->assertCount(1, $offices);
        $this->assertSame('109012', $offices[0]['code']);
        $this->assertSame(0.2, $offices[0]['distance_km']);
        $this->assertSame('Пн–Пт 09:00–21:00, Сб 10:00–18:00', $offices[0]['hours']);
    }

    public function test_calculate_endpoint_returns_422_with_message_when_office_is_not_selected(): void
    {
        Http::fake();

        $this->postJson('/api/public/delivery/pochta/calculate', [
            'delivery_method_code' => 'russian_post_office',
            'destination' => ['city' => 'Москва'],
            'items' => $this->items(),
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_calculate_endpoint_returns_503_when_integration_is_disabled(): void
    {
        Http::fake();
        config(['services.pochta.enabled' => false]);

        $this->postJson('/api/public/delivery/pochta/calculate', [
            'delivery_method_code' => 'russian_post_office',
            'destination' => ['index' => '101000'],
            'items' => $this->items(),
        ])->assertStatus(503)->assertJson(['success' => false]);
    }

    public function test_checkout_revalidation_replaces_client_price_with_fresh_tariff(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response($this->tariffResponse())]);
        $items = [['name' => 'Тест', 'final_price' => 1500, 'quantity' => 2]];

        $delivery = app(PochtaDeliveryService::class)->revalidateCheckout([
            'provider' => 'pochta', 'tariff_code' => 'ONLINE_PARCEL', 'price' => 1.0, 'delivery_type' => 'courier',
            'destination' => ['index' => '101000', 'city' => 'Москва', 'country_code' => 'ru'],
            'pvz' => ['code' => '101000', 'type' => 'ГОПС', 'address' => 'Никольская ул, 7', 'coordinates' => [37.62, 55.75]],
        ], $items, 'russian_post_office');

        $this->assertSame('pochta', $delivery['provider']);
        $this->assertSame('office', $delivery['delivery_type']);
        $this->assertSame(437.98, $delivery['price']);
        $this->assertSame('RU', $delivery['destination']['country_code']);
        $this->assertSame('101000', $delivery['pvz']['code']);
    }

    public function test_checkout_revalidation_rejects_unknown_tariff_and_foreign_office(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response($this->tariffResponse())]);
        $service = app(PochtaDeliveryService::class);
        $items = [['name' => 'Тест', 'final_price' => 1500, 'quantity' => 1]];

        try {
            $service->revalidateCheckout(['tariff_code' => 'EMS', 'destination' => ['index' => '101000'], 'pvz' => ['code' => '101000']], $items, 'russian_post_office');
            $this->fail('Тариф, которого нет у способа, принят');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $service->revalidateCheckout(['tariff_code' => 'ONLINE_PARCEL', 'destination' => ['index' => '101000'], 'pvz' => ['code' => '109012']], $items, 'russian_post_office');
    }

    public function test_client_failure_is_reported_as_unavailable(): void
    {
        Http::fake(['*/1.0/tariff' => Http::response(['error' => 'x'], 500)]);

        $this->expectException(RuntimeException::class);

        app(PochtaDeliveryService::class)->calculateTariffs('russian_post_office', ['index' => '101000'], $this->items());
    }
}
