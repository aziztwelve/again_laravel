<?php

namespace Tests\Feature\Admin;

use App\Models\DeliveryServiceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Справочник складов СДЭК для выбора «Города отправки» в настройках
 * интеграции (см. docs/del/cdek.md) и сохранение названия города.
 */
class CdekWarehousesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('cdek:warehouses:ru');

        DeliveryServiceSetting::query()->firstOrCreate(
            ['service_name' => 'cdek'],
            ['settings' => [
                'enabled' => true,
                'mode' => 'sandbox',
                'account' => 'test-account',
                'secure_password' => 'test-secret',
            ]],
        );

        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/deliverypoints*' => Http::response($this->points()),
        ]);

        Sanctum::actingAs(User::factory()->create());
    }

    /** @return array<int, array{code: string, type: string, location: array<string, mixed>>}> */
    private function points(): array
    {
        return array_map(
            fn (array $point) => ['code' => $point['code'], 'type' => 'PVZ', 'location' => $point['location']],
            [
                ['code' => 'SPB1', 'location' => ['city' => 'Санкт-Петербург', 'city_code' => 137, 'region' => 'Санкт-Петербург', 'address' => 'Невский проспект, 1', 'address_full' => '', 'postal_code' => '190000']],
                ['code' => 'MSK2', 'location' => ['city' => 'Москва', 'city_code' => 44, 'region' => 'Москва', 'address' => 'Ленинский проспект, 2', 'address_full' => '', 'postal_code' => '119071']],
                ['code' => 'MSK1', 'location' => ['city' => 'Москва', 'city_code' => 44, 'region' => 'Москва', 'address' => 'Арбат, 10', 'address_full' => '', 'postal_code' => '119002']],
                ['code' => 'MOSOBL1', 'location' => ['city' => 'Подольск', 'city_code' => 246, 'region' => 'Московская область', 'address' => 'Ленина, 5', 'address_full' => '', 'postal_code' => '142100']],
                ['code' => 'EKB1', 'location' => ['city' => 'Екатеринбург', 'city_code' => 267, 'region' => 'Свердловская область', 'address' => 'Ленина, 8', 'address_full' => '', 'postal_code' => '620000']],
            ],
        );
    }

    public function test_warehouses_are_sorted_and_limited(): void
    {
        $this->getJson('/api/third-party-integrations/cdek/warehouses?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'warehouses');

        $this->getJson('/api/third-party-integrations/cdek/warehouses')
            ->assertOk()
            ->assertJsonPath('warehouses.0.city', 'Екатеринбург')
            ->assertJsonPath('warehouses.1.city', 'Москва')
            ->assertJsonPath('warehouses.1.address', 'Арбат, 10')
            ->assertJsonPath('warehouses.2.address', 'Ленинский проспект, 2')
            ->assertJsonPath('warehouses.3.city', 'Подольск')
            ->assertJsonPath('warehouses.4.city', 'Санкт-Петербург');
    }

    public function test_search_ranks_city_prefix_before_region_and_address(): void
    {
        $this->getJson('/api/third-party-integrations/cdek/warehouses?query=Моск')
            ->assertOk()
            ->assertJsonCount(4, 'warehouses')
            ->assertJsonPath('warehouses.0.city', 'Москва')
            ->assertJsonPath('warehouses.0.city_code', 44)
            ->assertJsonPath('warehouses.3.city', 'Подольск');

        $this->getJson('/api/third-party-integrations/cdek/warehouses?query=Ленин')
            ->assertOk()
            ->assertJsonCount(3, 'warehouses')
            ->assertJsonPath('warehouses.1.address', 'Ленинский проспект, 2');

        $this->getJson('/api/third-party-integrations/cdek/warehouses?query=НетТакогоГорода')
            ->assertOk()
            ->assertJsonCount(0, 'warehouses');
    }

    public function test_warehouse_list_is_cached_after_first_request(): void
    {
        $this->getJson('/api/third-party-integrations/cdek/warehouses')->assertOk();
        $this->getJson('/api/third-party-integrations/cdek/warehouses?query=Москва')->assertOk();

        $deliverypointsCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/v2/deliverypoints'))
            ->count();

        $this->assertSame(1, $deliverypointsCalls);
    }

    public function test_settings_save_persists_sender_city_name(): void
    {
        $this->putJson('/api/third-party-integrations/cdek/settings', [
            'settings' => [
                'enabled' => true,
                'sender' => [
                    'city_name' => 'Москва',
                    'city_code' => 44,
                    'address' => 'Арбат, 10',
                ],
            ],
        ])->assertOk()->assertJsonPath('settings.sender.city_name', 'Москва');

        $saved = DeliveryServiceSetting::query()->where('service_name', 'cdek')->value('settings');
        $this->assertSame('Москва', data_get($saved, 'sender.city_name'));
        $this->assertSame(44, data_get($saved, 'sender.city_code'));
    }

    public function test_guest_cannot_list_warehouses(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/third-party-integrations/cdek/warehouses')->assertUnauthorized();
    }
}
