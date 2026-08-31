<?php

namespace Tests\Unit\Services\Delivery;

use App\Models\Product;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Delivery\Cdek\CdekClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdekClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_authenticates_and_sends_bearer_token(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response([
                'access_token' => 'test-token', 'expires_in' => 3600,
            ]),
            'https://api.edu.cdek.ru/v2/location/suggest/cities*' => Http::response([['code' => 44]]),
        ]);

        $client = new CdekClient([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
        ]);
        $result = $client->request('GET', '/v2/location/suggest/cities', query: ['name' => 'Москва']);

        $this->assertTrue($result['successful']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/location/suggest/cities?name=%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_rejects_requests_when_not_configured(): void
    {
        $result = (new CdekClient(['enabled' => false]))->request('GET', '/v2/deliverypoints');

        $this->assertFalse($result['successful']);
        $this->assertSame(503, $result['status']);
    }

    public function test_it_revalidates_the_selected_pickup_tariff_with_server_item_data(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response([
                'access_token' => 'test-token', 'expires_in' => 3600,
            ]),
            'https://api.edu.cdek.ru/v2/deliverypoints*' => Http::response([[
                'code' => 'MSK1', 'type' => 'PVZ',
                'location' => ['address' => 'Тестовый адрес', 'longitude' => 37.6, 'latitude' => 55.7],
            ]]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response([
                'tariff_codes' => [[
                    'tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад',
                    'delivery_mode' => 2, 'delivery_sum' => 420,
                    'period_min' => 2, 'period_max' => 4,
                ]],
            ]),
        ]);

        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
            'sender' => ['city_code' => 44, 'address' => 'Москва'],
        ]);
        $product = new Product(['name' => 'Товар', 'weight' => 1000, 'length' => 30, 'width' => 20, 'height' => 10]);

        $delivery = $service->revalidateCheckout([
            'delivery_type' => 'pickup', 'tariff_code' => 136,
            'destination' => ['city_code' => 44], 'pvz' => ['code' => 'MSK1'],
        ], [[
            'model' => $product, 'name' => 'Товар', 'final_price' => 1000, 'quantity' => 1,
        ]]);

        $this->assertSame(420.0, $delivery['price']);
        $this->assertSame('MSK1', $delivery['pvz']['code']);
        $this->assertSame('Тестовый адрес', $delivery['pvz']['address']);
    }

    public function test_it_calculates_postamat_tariffs_and_applies_the_configured_display_name(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [[
                'tariff_code' => 136, 'tariff_name' => 'Посылка склад-постамат', 'delivery_mode' => 2,
                'delivery_sum' => 420, 'period_min' => 2, 'period_max' => 4,
            ]]]),
        ]);

        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'tariff_display' => ['name_source' => 'delivery', 'description_source' => 'full'],
        ]);

        $tariff = $service->calculateTariffs('postamat', ['city_code' => 44], [['name' => 'Товар', 'weight' => 100]], 'MSKPOST1')[0];

        $this->assertSame('СДЭК: Постамат', $tariff['display_name']);
        $this->assertSame('Посылка склад-постамат', $tariff['display_description']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/calculator/tarifflist'
            && $request['delivery_point'] === 'MSKPOST1');
    }
}
