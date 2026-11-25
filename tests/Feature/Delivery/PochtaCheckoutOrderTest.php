<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\Product;
use App\Services\Delivery\PochtaDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Заказ из публичного чекаута со способом доставки «Почта РФ»: данные доставки сохраняются
 * как Почта (а не как Яндекс), цена берётся из свежего расчёта, индекс попадает в адрес.
 */
class PochtaCheckoutOrderTest extends TestCase
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
        ]);
    }

    private function method(string $code, string $name): DeliveryMethod
    {
        $method = DeliveryMethod::where('code', $code)->first() ?? DeliveryMethod::create([
            'code' => $code, 'name' => $name, 'description' => $name, 'is_active' => true, 'settings' => [],
            'provider_class' => PochtaDeliveryService::class,
        ]);
        $method->update(['name' => $name, 'provider_class' => PochtaDeliveryService::class]);

        return $method;
    }

    private function payload(DeliveryMethod $method, array $deliveryData, array $address = []): array
    {
        $product = Product::create([
            'name' => 'Pochta product '.uniqid(), 'price' => 1000, 'currency' => 'RUB',
            'is_active' => true, 'has_variants' => false, 'stock_quantity' => 100,
        ]);

        return [
            'delivery_address' => $address + ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая улица, 1'],
            'user' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000', 'email' => 'pochta-'.uniqid().'@example.com'],
            'recipient' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'delivery_method' => ['name' => $method->name],
            'delivery_data' => $deliveryData,
            'payment_method' => 'card_ru',
        ];
    }

    private function fakePochta(): void
    {
        Http::fake([
            '*/1.0/clean/address' => Http::response([['id' => 'a1', 'index' => '125009', 'quality-code' => 'GOOD']]),
            '*/1.0/tariff' => Http::response(['total-rate' => 35900, 'total-vat' => 7898, 'delivery-time' => ['max-days' => 7]]),
        ]);
    }

    public function test_office_order_is_saved_as_pochta_and_client_price_is_replaced(): void
    {
        $this->fakePochta();
        $method = $this->method('russian_post_office', 'Почта РФ: в отделение');

        $response = $this->postJson('/api/public/orders', $this->payload($method, [
            'provider' => 'pochta', 'delivery_type' => 'office', 'tariff_code' => 'ONLINE_PARCEL', 'price' => 1,
            'destination' => ['index' => '101000', 'city' => 'Москва', 'country_code' => 'RU'],
            'pvz' => ['code' => '101000', 'type' => 'ГОПС', 'address' => 'Никольская ул, 7', 'coordinates' => [37.62, 55.75]],
        ]));

        $response->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        $this->assertSame('pochta', $order->delivery_data['provider']);
        $this->assertSame('office', $order->delivery_data['delivery_type']);
        $this->assertSame('101000', $order->delivery_data['pvz']['code']);
        $this->assertSame(437.98, (float) $order->delivery_data['price']);
        $this->assertSame(437.98, (float) $order->delivery_cost);
        $this->assertSame('101000', (string) $order->address->postal_code);
    }

    public function test_office_method_without_selected_office_is_rejected(): void
    {
        $this->fakePochta();
        $method = $this->method('russian_post_office', 'Почта РФ: в отделение');

        $this->postJson('/api/public/orders', $this->payload($method, [
            'provider' => 'pochta', 'delivery_type' => 'office', 'tariff_code' => 'ONLINE_PARCEL', 'price' => 1,
            'destination' => ['city' => 'Москва', 'country_code' => 'RU'],
        ]))->assertStatus(422);
    }

    public function test_courier_order_keeps_postal_index_found_by_address(): void
    {
        $this->fakePochta();
        $method = $this->method('russian_post_courier', 'Почта РФ: Курьер');

        $response = $this->postJson('/api/public/orders', $this->payload($method, [
            'provider' => 'pochta', 'delivery_type' => 'courier', 'tariff_code' => 'ONLINE_COURIER', 'price' => 1,
            'destination' => ['city' => 'Москва', 'address' => 'Тестовая улица, 1', 'country_code' => 'RU'],
        ]));

        $response->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        $this->assertSame('pochta', $order->delivery_data['provider']);
        $this->assertSame('125009', $order->delivery_data['destination']['index']);
        $this->assertSame('125009', (string) $order->address->postal_code);
    }

    public function test_pochta_method_with_foreign_provider_data_is_rejected(): void
    {
        $this->fakePochta();
        $method = $this->method('russian_post_courier', 'Почта РФ: Курьер');

        $this->postJson('/api/public/orders', $this->payload($method, [
            'provider' => 'yandex', 'delivery_type' => 'courier', 'offer_id' => 'x', 'price' => 1,
        ]))->assertStatus(422);
    }

    public function test_unavailable_pochta_does_not_create_an_order(): void
    {
        $this->fakePochta();
        config(['services.pochta.enabled' => false]);
        $method = $this->method('russian_post_office', 'Почта РФ: в отделение');
        $before = Order::count();

        $this->postJson('/api/public/orders', $this->payload($method, [
            'provider' => 'pochta', 'delivery_type' => 'office', 'tariff_code' => 'ONLINE_PARCEL', 'price' => 1,
            'destination' => ['index' => '101000', 'country_code' => 'RU'],
            'pvz' => ['code' => '101000'],
        ]))->assertStatus(503);

        $this->assertSame($before, Order::count());
    }
}
