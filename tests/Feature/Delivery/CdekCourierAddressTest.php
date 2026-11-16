<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Курьер СДЭК: адрес заказа и данные для модуля СДЭК (код города, тариф) не должны расходиться.
 * Заказы 41378 (сайт без тарифа) и 41348 (менеджер сменил город).
 */
class CdekCourierAddressTest extends TestCase
{
    use DatabaseTransactions;

    private const ITEMS = [['name' => 'Трусы', 'final_price' => 1, 'quantity' => 1]];

    private function courierMethod(): DeliveryMethod
    {
        return DeliveryMethod::where('code', 'cdek_courier')->firstOrFail();
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Courier product '.uniqid(),
            'price' => 1000,
            'currency' => 'RUB',
            'is_active' => true,
            'has_variants' => false,
            'stock_quantity' => 100,
        ]);
    }

    private function partialService(array $cities): CdekDeliveryService
    {
        $service = Mockery::mock(CdekDeliveryService::class)->makePartial();
        $service->shouldReceive('cities')->andReturn($cities);

        return $service;
    }

    public function test_city_code_is_found_by_exact_label_and_by_plain_name(): void
    {
        \App\Models\Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия']);
        $service = $this->partialService([
            ['code' => 1, 'full_name' => 'Москва, Московская область, Россия'],
            ['code' => 44, 'full_name' => 'Москва, Россия'],
        ]);

        $this->assertSame(44, $service->resolveCityCode('Москва, Россия', 'RU'));
        $this->assertSame(44, $service->resolveCityCode('Москва, Россия', 'Россия'));
        // Без региона — первый результат СДЭК.
        $this->assertSame(1, $service->resolveCityCode('г Москва', 'RU'));
    }

    public function test_ambiguous_city_with_region_is_not_guessed(): void
    {
        $service = $this->partialService([
            ['code' => 10, 'full_name' => 'Подольск, Московская область, Россия'],
            ['code' => 11, 'full_name' => 'Подольск, Украина'],
        ]);

        $this->assertNull($service->resolveCityCode('Подольск, Калужская область, Россия', 'RU'));
    }

    public function test_changing_city_recalculates_code_and_tariff(): void
    {
        $service = $this->partialService([['code' => 4961, 'full_name' => 'Астана, Казахстан']]);
        $service->shouldReceive('calculateTariffs')->once()->andReturn([
            ['tariff_code' => 482, 'tariff_name' => 'Курьер', 'price' => 900.0, 'delivery_mode' => 3],
        ]);

        $old = [
            'provider' => 'cdek', 'delivery_type' => 'courier', 'tariff_code' => 137, 'price' => 500,
            'destination' => ['city_code' => 2, 'address' => 'Старая'],
        ];
        $result = $service->completeCourierFromAddress($old, ['city' => 'Астана', 'country' => 'KZ', 'address' => 'Сарайшык 34'], self::ITEMS);

        $this->assertSame(['city_code' => 4961, 'address' => 'Сарайшык 34'], $result['destination']);
        $this->assertSame(482, $result['tariff_code']);
        $this->assertSame(900.0, $result['price']);
    }

    public function test_unresolved_city_leaves_delivery_data_unchanged(): void
    {
        $service = $this->partialService([]);
        $service->shouldNotReceive('calculateTariffs');
        $old = ['provider' => 'cdek', 'delivery_type' => 'courier', 'destination' => ['city_code' => 2, 'address' => 'Старая']];

        $this->assertSame($old, $service->completeCourierFromAddress($old, ['city' => 'Нигде', 'country' => 'RU', 'address' => 'Улица'], self::ITEMS));
    }

    public function test_manager_address_edit_refreshes_cdek_courier_data(): void
    {
        $order = Order::create([
            'order_number' => 'CC-'.uniqid(),
            'status' => 'new',
            'payment_status' => 'pending',
            'total_amount' => 1000,
            'delivery_method_id' => $this->courierMethod()->id,
            'delivery_cost' => 0,
            'delivery_data' => null,
        ]);

        $service = $this->partialService([['code' => 44, 'full_name' => 'Москва, Россия']]);
        $service->shouldReceive('calculateTariffs')->andReturn([
            ['tariff_code' => 137, 'tariff_name' => 'Склад-дверь', 'price' => 580.0, 'delivery_mode' => 3],
        ]);
        $this->app->instance(CdekDeliveryService::class, $service);

        $this->assertTrue(app(OrderUpdateService::class)->update($order, [
            'delivery_address' => ['country' => 'RU', 'city' => 'Москва, Россия', 'address' => 'Удальцова 1'],
        ]));

        $data = $order->fresh()->delivery_data;
        $this->assertSame('cdek', $data['provider']);
        $this->assertSame('courier', $data['delivery_type']);
        $this->assertSame(44, $data['destination']['city_code']);
        $this->assertSame('Удальцова 1', $data['destination']['address']);
        $this->assertSame(137, $data['tariff_code']);
        // Цену доставки в заказе правка адреса не меняет.
        $this->assertSame('0.00', (string) $order->fresh()->delivery_cost);
    }

    public function test_address_edit_of_other_delivery_method_does_not_touch_delivery_data(): void
    {
        $method = DeliveryMethod::where('code', '!=', 'cdek_courier')->firstOrFail();
        $order = Order::create([
            'order_number' => 'CC-'.uniqid(),
            'status' => 'new',
            'payment_status' => 'pending',
            'total_amount' => 1000,
            'delivery_method_id' => $method->id,
            'delivery_data' => null,
        ]);
        $service = Mockery::mock(CdekDeliveryService::class);
        $service->shouldNotReceive('completeCourierFromAddress');
        $this->app->instance(CdekDeliveryService::class, $service);

        $this->assertTrue(app(OrderUpdateService::class)->update($order, [
            'delivery_address' => ['country' => 'RU', 'city' => 'Москва', 'address' => 'Улица 1'],
        ]));
        $this->assertNull($order->fresh()->delivery_data);
    }

    public function test_site_checkout_rejects_cdek_courier_without_cdek_data(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/public/orders', [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Астрахань', 'address' => 'Челюскинцев 81'],
            'user' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000', 'email' => 'cc-'.uniqid().'@example.com'],
            'recipient' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
            'delivery_method' => ['name' => $this->courierMethod()->name],
            'payment_method' => 'card_ru',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('СДЭК', (string) $response->json('message'));
        $this->assertSame(0, Order::where('total_amount', 1000)->where('delivery_method_id', $this->courierMethod()->id)->count());
    }
}
