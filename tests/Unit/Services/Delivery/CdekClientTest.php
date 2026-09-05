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
                    'delivery_mode' => 4, 'delivery_sum' => 420,
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
                'tariff_code' => 368, 'tariff_name' => 'Посылка склад-постамат', 'delivery_mode' => 7,
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

    public function test_it_keeps_selected_warehouse_tariff_price_independent_from_free_shipping_threshold(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [[
                'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3,
                'delivery_sum' => 420.2, 'period_min' => 2, 'period_max' => 4,
            ]]]),
        ]);
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'tariff_mode' => 'sklad', 'tariff_codes' => [137], 'price_rules' => ['threshold' => 7000, 'add_cost' => 19.1, 'rounded' => '1'],
        ]);

        $tariff = $service->calculateTariffs('courier', ['city_code' => 44, 'address' => 'Тест'], [['name' => 'Товар', 'weight' => 100, 'price' => 7000]])[0];

        $this->assertSame(440.0, $tariff['price']);
        $this->assertSame(3, $tariff['delivery_mode']);

        $belowThreshold = $service->calculateTariffs('courier', ['city_code' => 44, 'address' => 'Тест'], [['name' => 'Товар', 'weight' => 100, 'price' => 6999]])[0];
        $this->assertSame(440.0, $belowThreshold['price']);
    }

    public function test_it_adds_the_configured_days_offset_to_the_delivery_period(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [[
                'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3,
                'delivery_sum' => 420, 'period_min' => 2, 'period_max' => 4,
            ]]]),
        ]);
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'delivery_days_offset' => 3,
        ]);

        $tariff = $service->calculateTariffs('courier', ['city_code' => 44, 'address' => 'Тест'], [['name' => 'Товар', 'weight' => 100]])[0];

        $this->assertSame(['min' => 5, 'max' => 7], $tariff['period']);
    }

    public function test_package_uses_item_measurements_with_settings_fallback(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [[
                'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3,
                'delivery_sum' => 420, 'period_min' => 2, 'period_max' => 4,
            ]]]),
        ]);
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'default_package' => ['weight' => 80, 'length' => 26, 'width' => 21, 'height' => 4],
        ]);

        // Товар с габаритами из карточки + товар без них (fallback настроек).
        $service->calculateTariffs('courier', ['city_code' => 44, 'address' => 'Тест'], [
            ['name' => 'С данными', 'weight' => 350, 'length' => 35, 'width' => 25, 'height' => 12, 'quantity' => 1],
            ['name' => 'Без данных', 'quantity' => 2],
        ]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'tarifflist')
            && $request['packages'][0]['weight'] === 350 + 80 * 2
            && $request['packages'][0]['length'] === 35
            && $request['packages'][0]['width'] === 25
            && $request['packages'][0]['height'] === 12 + 4 * 2);
    }

    public function test_it_returns_only_the_configured_default_tariff_for_each_delivery_type(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.edu.cdek.ru/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [
                ['tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3, 'delivery_sum' => 400, 'period_min' => 1, 'period_max' => 2],
                ['tariff_code' => 480, 'tariff_name' => 'Экспресс дверь-дверь', 'delivery_mode' => 1, 'delivery_sum' => 800, 'period_min' => 1, 'period_max' => 2],
            ]]),
        ]);
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'tariff_mode' => 'sklad', 'tariff_codes' => [136, 137, 368],
        ]);

        $tariffs = $service->calculateTariffs('courier', ['city_code' => 44, 'address' => 'Тест'], [['name' => 'Товар', 'weight' => 100]]);

        $this->assertSame([137], array_column($tariffs, 'tariff_code'));
    }

    public function test_it_requests_print_documents_for_a_created_order(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            // Накладная: 202 без url → поллим GET до готовности.
            'https://api.edu.cdek.ru/v2/print/orders' => Http::response(['entity' => ['uuid' => 'print-uuid']], 202),
            'https://api.edu.cdek.ru/v2/print/orders/print-uuid' => Http::response(['url' => 'https://print.cdek.ru/waybill.pdf']),
            // ШК: готов сразу.
            'https://api.edu.cdek.ru/v2/print/barcodes' => Http::response(['url' => 'https://print.cdek.ru/barcodes.pdf']),
        ]);
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'], 'sender' => ['city_code' => 44, 'address' => 'Москва'],
        ]);
        $cdekOrder = new \App\Models\CdekOrder(['cdek_uuid' => 'uuid-1', 'order_id' => 7]);

        $waybill = $service->printWaybill($cdekOrder);
        $barcode = $service->printBarcode($cdekOrder);

        $this->assertTrue($waybill['successful']);
        $this->assertSame('https://print.cdek.ru/waybill.pdf', $waybill['data']['url']);
        $this->assertTrue($barcode['successful']);
        $this->assertSame('https://print.cdek.ru/barcodes.pdf', $barcode['data']['url']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/print/orders'
            && $request['orders'] === [['order_uuid' => 'uuid-1']]
            && $request['format'] === 'pdf');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/print/orders/print-uuid');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/print/barcodes'
            && $request['orders'] === [['order_uuid' => 'uuid-1']]
            && $request['format'] === 'A6');
    }

    public function test_it_requires_created_order_for_print_documents(): void
    {
        $service = new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->printWaybill(new \App\Models\CdekOrder(['order_id' => 7]));
    }
}
