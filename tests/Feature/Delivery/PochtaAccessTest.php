<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delivery\PochtaDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Пока Почта России не открыта всем, её способы видит только покупатель из services.pochta.testers.
 */
class PochtaAccessTest extends TestCase
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
            'services.pochta.public' => false,
            'services.pochta.testers' => ['Tester@Example.com'],
        ]);
        foreach (PochtaDeliveryService::METHOD_CODES as $code) {
            $method = DeliveryMethod::where('code', $code)->first() ?? DeliveryMethod::create([
                'code' => $code, 'name' => 'Почта '.$code, 'description' => $code, 'settings' => [],
                'provider_class' => PochtaDeliveryService::class, 'is_active' => true,
            ]);
            $method->update(['is_active' => true]);
        }
        Http::fake([
            '*/1.0/tariff' => Http::response(['total-rate' => 35900, 'total-vat' => 7898, 'delivery-time' => ['max-days' => 7]]),
        ]);
    }

    private function listedCodes(): array
    {
        return collect($this->getJson('/api/public/delivery/methods?active=1')->assertOk()->json('data'))
            ->pluck('delivery_type_code')->all();
    }

    private function actAs(string $email): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => $email]), ['*'], 'sanctum');
    }

    private function calculate(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/public/delivery/pochta/calculate', [
            'delivery_method_code' => 'russian_post_office',
            'destination' => ['index' => '101000'],
            'items' => [['name' => 'Тест', 'price' => 1000, 'quantity' => 1]],
        ]);
    }

    public function test_guest_does_not_see_pochta_methods_but_still_sees_others(): void
    {
        $codes = $this->listedCodes();

        $this->assertSame([], array_values(array_intersect($codes, PochtaDeliveryService::METHOD_CODES)));
        $this->assertContains('cdek_pickup', $codes);
    }

    public function test_listed_tester_sees_pochta_methods_regardless_of_email_case(): void
    {
        $this->actAs('tester@example.COM');

        $this->assertSame([], array_values(array_diff(PochtaDeliveryService::METHOD_CODES, $this->listedCodes())));
    }

    public function test_other_customer_does_not_see_pochta_methods(): void
    {
        $this->actAs('someone@example.com');

        $this->assertSame([], array_values(array_intersect($this->listedCodes(), PochtaDeliveryService::METHOD_CODES)));
    }

    public function test_opening_to_everyone_shows_methods_to_guests(): void
    {
        config(['services.pochta.public' => true]);

        $this->assertSame([], array_values(array_diff(PochtaDeliveryService::METHOD_CODES, $this->listedCodes())));
    }

    public function test_calculate_and_offices_are_closed_for_guests_and_open_for_tester(): void
    {
        $this->calculate()->assertStatus(403);
        $this->getJson('/api/public/delivery/pochta/offices?city=Москва')->assertStatus(403);

        $this->actAs('tester@example.com');

        $this->calculate()->assertOk()->assertJson(['success' => true]);
    }

    public function test_guest_cannot_place_order_with_pochta_even_if_method_name_is_sent(): void
    {
        $method = DeliveryMethod::where('code', 'russian_post_office')->firstOrFail();
        $product = Product::create([
            'name' => 'Access product '.uniqid(), 'price' => 1000, 'currency' => 'RUB',
            'is_active' => true, 'has_variants' => false, 'stock_quantity' => 100,
        ]);
        $before = Order::count();

        $this->postJson('/api/public/orders', [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая улица, 1'],
            'user' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000', 'email' => 'guest-'.uniqid().'@example.com'],
            'recipient' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'delivery_method' => ['name' => $method->name],
            'delivery_data' => [
                'provider' => 'pochta', 'delivery_type' => 'office', 'tariff_code' => 'ONLINE_PARCEL', 'price' => 1,
                'destination' => ['index' => '101000', 'country_code' => 'RU'], 'pvz' => ['code' => '101000'],
            ],
            'payment_method' => 'card_ru',
        ])->assertStatus(422);

        $this->assertSame($before, Order::count());
    }
}
