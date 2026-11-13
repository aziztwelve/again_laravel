<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Jobs\CreateCdekOrderJob;
use App\Models\CdekOrder;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShipmentStatus;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Заказ 40747: СДЭК принял заявку (202) и тут же отклонил её (INVALID: посылка
 * не влезает в постамат), но сайт писал «создана успешно» и переводил заказ в
 * «Отгружен», хотя посылки в СДЭК не было и трека тоже.
 */
class CdekRejectedOrderTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): CdekDeliveryService
    {
        return new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
            'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'default_package' => ['weight' => 80, 'length' => 26, 'width' => 21, 'height' => 4],
        ]);
    }

    private function order(string $methodCode = 'cdek_pickup', array $delivery = [], string $phone = '+79990000000'): array
    {
        $method = DeliveryMethod::query()->where('code', $methodCode)->firstOrFail();
        $order = Order::create([
            'order_number' => 'CDEK-'.uniqid(),
            'status' => 'processing',
            'payment_status' => 'paid',
            'total_amount' => 5000,
            'delivery_method_id' => $method->id,
            'delivery_cost' => 0,
            'delivery_data' => $delivery + ['provider' => 'cdek', 'delivery_type' => 'pickup', 'tariff_code' => 136, 'pvz' => ['code' => 'MSK171']],
        ]);
        $order->address()->create([
            'city' => 'Москва', 'address' => 'Арбат, 10',
            'recipient_first_name' => 'Иван', 'recipient_last_name' => 'Иванов', 'recipient_phone' => $phone,
        ]);

        $cdekOrder = CdekOrder::create([
            'order_id' => $order->id, 'external_order_number' => 'order-'.$order->id,
            'delivery_type' => 'pickup', 'tariff_code' => 136, 'creation_state' => 'ACCEPTED',
        ]);

        return [$order, $cdekOrder];
    }

    private function fakeCdekOrder(array $entity, array $requests = []): void
    {
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/orders*' => Http::response(['entity' => $entity, 'requests' => $requests]),
        ]);
    }

    private function invalidResponse(): void
    {
        $this->fakeCdekOrder([
            'uuid' => 'cdek-uuid-invalid',
            'statuses' => [
                ['code' => 'INVALID', 'name' => 'Некорректный заказ', 'city' => 'Офис СДЭК', 'date_time' => '2026-11-09T09:18:45+0000'],
                ['code' => 'ACCEPTED', 'name' => 'Принят', 'city' => 'Офис СДЭК', 'date_time' => '2026-11-09T09:18:44+0000'],
            ],
        ], [[
            'type' => 'CREATE', 'state' => 'INVALID',
            'errors' => [['code' => 'error_validate_at_least_one_postamat_cell_fits_package', 'message' => 'Габариты места больше ячеек в выбранном постамате.']],
        ]]);
    }

    public function test_rejected_request_is_marked_invalid_and_order_is_not_shipped(): void
    {
        [$order, $cdekOrder] = $this->order();
        $this->invalidResponse();

        $this->service()->sync($cdekOrder);

        $cdekOrder->refresh();
        $this->assertSame('INVALID', $cdekOrder->creation_state);
        $this->assertStringContainsString('Габариты места больше ячеек', (string) $cdekOrder->last_error);
        $this->assertSame(ShipmentStatus::FAILED, $cdekOrder->internal_status);
        $this->assertNull($cdekOrder->cdek_number);
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status, 'Заказ без посылки не должен становиться «Отгружен».');
    }

    public function test_rejection_is_written_to_order_history_only_once(): void
    {
        [$order, $cdekOrder] = $this->order();
        $this->invalidResponse();

        $this->service()->sync($cdekOrder);
        $this->service()->sync($cdekOrder->fresh());

        $notes = $order->history()->where('description', 'like', 'СДЭК не принял заявку%')->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Габариты места больше ячеек', $notes->first()->description);
    }

    public function test_request_still_being_checked_is_not_reported_as_successful(): void
    {
        [, $cdekOrder] = $this->order();
        $this->fakeCdekOrder(
            ['uuid' => 'cdek-uuid-pending', 'statuses' => [['code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-11-09T09:18:44+0000']]],
            [['type' => 'CREATE', 'state' => 'ACCEPTED']],
        );

        $this->service()->sync($cdekOrder);

        $this->assertSame('ACCEPTED', $cdekOrder->fresh()->creation_state);
    }

    public function test_successful_request_with_cdek_number_is_successful(): void
    {
        [, $cdekOrder] = $this->order();
        $this->fakeCdekOrder(
            ['uuid' => 'cdek-uuid-ok', 'cdek_number' => '10337704825', 'statuses' => [['code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-11-09T09:18:44+0000']]],
            [['type' => 'CREATE', 'state' => 'SUCCESSFUL']],
        );

        $this->service()->sync($cdekOrder);

        $cdekOrder->refresh();
        $this->assertSame('SUCCESSFUL', $cdekOrder->creation_state);
        $this->assertNull($cdekOrder->last_error);
    }

    public function test_unknown_cdek_status_leaves_the_order_status_alone(): void
    {
        [$order, $cdekOrder] = $this->order();
        $this->fakeCdekOrder(
            ['uuid' => 'cdek-uuid-new', 'cdek_number' => '10337704826', 'statuses' => [['code' => 'BRAND_NEW_CODE', 'name' => 'Новый статус', 'date_time' => '2026-11-09T10:00:00+0000']]],
        );

        $this->service()->sync($cdekOrder);

        $this->assertSame(ShipmentStatus::PROCESSING, $cdekOrder->fresh()->internal_status);
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    public function test_real_transit_status_still_ships_the_order(): void
    {
        [$order, $cdekOrder] = $this->order();
        $this->fakeCdekOrder(
            ['uuid' => 'cdek-uuid-transit', 'cdek_number' => '10337704827', 'statuses' => [['code' => 'SENT_TO_TRANSIT_CITY', 'name' => 'Отправлен в город-транзит', 'date_time' => '2026-11-10T10:00:00+0000']]],
            [['type' => 'CREATE', 'state' => 'SUCCESSFUL']],
        );

        $this->service()->sync($cdekOrder);

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $cdekOrder->fresh()->internal_status);
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_create_job_rejects_an_invalid_phone_before_calling_cdek(): void
    {
        Http::preventStrayRequests();
        [$order, $cdekOrder] = $this->order(phone: 'Кочеткова Ольга');
        $cdekOrder->update(['creation_state' => 'QUEUED']);

        (new CreateCdekOrderJob($order->id))->handle($this->service());

        $cdekOrder->refresh();
        $this->assertSame('INVALID', $cdekOrder->creation_state);
        $this->assertStringContainsString('Телефон получателя указан неверно', (string) $cdekOrder->last_error);
        $this->assertTrue($order->history()->where('description', 'like', 'Заявка СДЭК не создана%')->exists());
    }

    /** Заказ с одной позицией: product с размерами и весом. */
    private function postamatOrder(array $product): Order
    {
        [$order] = $this->order('cdek_postamat', ['delivery_type' => 'postamat', 'tariff_code' => 368, 'pvz' => ['code' => 'PZ-TEST-1']]);
        $model = Product::create($product + ['slug' => 'cdek-test-'.uniqid(), 'price' => 1000, 'currency' => 'RUB', 'is_active' => true, 'has_variants' => false, 'stock_quantity' => 5]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $model->id, 'quantity' => 1, 'price' => 1000, 'is_gift' => false]);

        return $order->fresh();
    }

    private function fakePostamat(): void
    {
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/deliverypoints*' => Http::response([[
                'code' => 'PZ-TEST-1', 'type' => 'POSTAMAT', 'weight_max' => 30,
                'dimensions' => [['width' => 30, 'height' => 20, 'depth' => 10]],
                'location' => ['city_code' => 44, 'address' => 'Москва, тестовая 1'],
            ]]),
        ]);
    }

    public function test_postamat_that_cannot_hold_the_parcel_is_reported_before_sending(): void
    {
        $this->fakePostamat();
        $order = $this->postamatOrder(['name' => 'Большая коробка', 'length' => 60, 'width' => 40, 'height' => 40, 'weight' => 500]);

        $error = $this->service()->parcelFitError($order);

        $this->assertNotNull($error);
        $this->assertStringContainsString('не помещается в ячейки', $error);
    }

    public function test_postamat_that_holds_the_parcel_passes(): void
    {
        $this->fakePostamat();
        $order = $this->postamatOrder(['name' => 'Небольшая коробка', 'length' => 20, 'width' => 15, 'height' => 5, 'weight' => 500]);

        $this->assertNull($this->service()->parcelFitError($order));
    }

    public function test_variant_weight_of_zero_falls_back_to_the_product_weight(): void
    {
        $product = (new Product())->forceFill(['name' => 'Любимый SET', 'weight' => '0.200', 'length' => '27.00', 'width' => '5.00', 'height' => '24.00']);
        $variant = (new ProductVariant())->forceFill(['sku' => 'again-setds-черный-m', 'weight' => '0.000', 'length' => '0.00', 'width' => '0.00', 'height' => '0.00']);
        $item = (new OrderItem())->forceFill(['quantity' => 2, 'price' => 4490]);
        $item->id = 1;
        $item->setRelation('product', $product);
        $item->setRelation('variant', $variant);
        $order = new Order();
        $order->id = 1;
        $order->setRelation('items', collect([$item]));

        $service = $this->service();
        $items = (new ReflectionMethod($service, 'orderItems'))->invoke($service, $order);
        $package = (new ReflectionMethod($service, 'package'))->invoke($service, $items, '40747');

        $this->assertSame(200, $package['items'][0]['weight'], 'вес единицы товара в граммах (раньше было 1)');
        $this->assertSame(400, $package['weight']);
        $this->assertSame(27, $package['length']);
        $this->assertLessThan(60, $package['height']);
    }
}
