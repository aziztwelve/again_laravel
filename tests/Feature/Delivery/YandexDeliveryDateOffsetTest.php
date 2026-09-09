<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryServiceSetting;
use App\Models\User;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class YandexDeliveryDateOffsetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_save_yandex_delivery_date_offset(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/third-party-integrations/yandex-delivery/settings')
            ->assertOk()
            ->assertJsonPath('settings.delivery_date_offset_days', 2);

        $this->putJson('/api/third-party-integrations/yandex-delivery/settings', [
            'delivery_date_offset_days' => 3,
            'token' => 'new-token',
            'widget_code' => 'widget-code',
            'api_url' => 'https://delivery.example.test',
        ])
            ->assertOk()
            ->assertJsonPath('settings.delivery_date_offset_days', 3)
            ->assertJsonPath('settings.api_token_configured', true)
            ->assertJsonPath('settings.widget_code', 'widget-code')
            ->assertJsonPath('settings.api_url', 'https://delivery.example.test');

        $this->assertSame(3, DeliveryServiceSetting::query()
            ->where('service_name', 'yandex')
            ->value('settings')['delivery_date_offset_days']);
    }

    public function test_yandex_offer_dates_include_configured_buffer(): void
    {
        DeliveryServiceSetting::create([
            'service_name' => 'yandex',
            'settings' => ['delivery_date_offset_days' => 2],
        ]);
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.platform_station_id' => 'source-1',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        Http::fake(['https://yandex.test/*' => Http::response([
            'offers' => [[
                'offer_id' => 'offer-1',
                'offer_details' => [
                    'pricing_total' => '100 RUB',
                    'delivery_interval' => [
                        'from' => '2026-09-10T10:00:00+03:00',
                        'to' => '2026-09-11T18:00:00+03:00',
                    ],
                ],
            ]],
        ])]);

        $offers = (new YandexDeliveryService([
            'enabled' => true,
            'mode' => 'sandbox',
            'token' => 'test-token',
            'platform_station_id' => 'source-1',
            'base_url' => ['sandbox' => 'https://yandex.test'],
            'delivery_date_offset_days' => 2,
        ]))->calculateOffers(
            deliveryType: 'pickup',
            items: [['name' => 'Товар', 'price' => 100, 'quantity' => 1]],
            pvzId: 'pvz-1',
            recipient: ['name' => 'Иван', 'phone' => '+79999999999'],
        );

        $this->assertSame('2026-09-12T10:00:00+03:00', $offers[0]['delivery_date']);
        $this->assertSame('2026-09-13T18:00:00+03:00', $offers[0]['delivery_interval']['to']);
    }
}
