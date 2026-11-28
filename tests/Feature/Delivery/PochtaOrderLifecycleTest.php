<?php

namespace Tests\Feature\Delivery;

use App\Jobs\PushOrderTracksToMoySkladJob;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Delivery\PochtaDeliveryService;
use App\Services\Notifications\OrderMessageBuilder;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Что происходит с заказом Почтой России после оформления: смена способа, правка адреса,
 * письмо об оплате, трек.
 */
class PochtaOrderLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Bus::fake();
        config([
            'services.pochta.enabled' => true,
            'services.pochta.token' => 'test-token',
            'services.pochta.login' => 'user@example.com',
            'services.pochta.password' => 'secret',
        ]);
        Http::fake([
            '*/1.0/clean/address' => Http::response([['id' => 'a1', 'index' => '630099', 'quality-code' => 'GOOD']]),
        ]);
    }

    private function method(string $code): DeliveryMethod
    {
        return DeliveryMethod::where('code', $code)->first() ?? DeliveryMethod::create([
            'code' => $code, 'name' => 'Метод '.$code, 'description' => $code, 'is_active' => true, 'settings' => [],
            'provider_class' => PochtaDeliveryService::class,
        ]);
    }

    private function order(string $type, array $extra = []): Order
    {
        $code = $type === 'office' ? 'russian_post_office' : 'russian_post_courier';
        $delivery = ['provider' => 'pochta', 'delivery_type' => $type, 'tariff_code' => 'ONLINE_PARCEL', 'tariff_name' => 'Посылка онлайн',
            'destination' => ['index' => '109012', 'city' => 'Москва', 'address' => 'Никольская ул, 7', 'country_code' => 'RU']];
        if ($type === 'office') {
            $delivery['pvz'] = ['code' => '109012', 'address' => 'Никольская ул, 7-9'];
        }

        $order = Order::create($extra + [
            'order_number' => 'PL-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending', 'total_amount' => 1000,
            'delivery_cost' => 400, 'delivery_method_id' => $this->method($code)->id, 'delivery_data' => $delivery,
        ]);
        $order->address()->create(['country' => 'Россия', 'city' => 'Москва', 'address' => 'Никольская ул, 7', 'postal_code' => '109012']);

        return $order;
    }

    private function update(Order $order, array $data): Order
    {
        $this->assertTrue(app(OrderUpdateService::class)->update($order, $data));

        return $order->fresh(['address', 'deliveryMethod']);
    }

    private function address(array $changes = []): array
    {
        return $changes + ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Никольская ул, 7', 'postal_code' => '109012'];
    }

    public function test_switch_to_cdek_pickup_drops_pochta_tariff(): void
    {
        $order = $this->update($this->order('office'), [
            'delivery_method_id' => $this->method('cdek_pickup')->id,
            'pvz_provider' => 'cdek', 'pvz_code' => 'MSK1', 'pvz_type' => 'PVZ',
        ]);

        $this->assertSame('cdek', $order->delivery_data['provider']);
        $this->assertSame('MSK1', $order->delivery_data['pvz']['code']);
        $this->assertArrayNotHasKey('tariff_code', $order->delivery_data);
        $this->assertArrayNotHasKey('destination', $order->delivery_data);

        app(CdekDeliveryService::class)->applyDefaultTariff($order);
        $this->assertSame(136, $order->delivery_data['tariff_code']);
    }

    public function test_switch_without_new_point_leaves_no_pochta_data(): void
    {
        $order = $this->update($this->order('office'), ['delivery_method_id' => $this->method('cdek_pickup')->id]);

        $this->assertNull($order->delivery_data);
    }

    public function test_cdek_refuses_order_that_still_has_pochta_data(): void
    {
        $order = $this->order('office', ['delivery_method_id' => $this->method('cdek_pickup')->id]);
        config(['services.cdek_delivery.sender.city_code' => 1210, 'services.cdek_delivery.sender.address' => 'Склад']);

        $this->assertStringContainsString('Почты России', (string) app(CdekDeliveryService::class)->readinessError($order));
    }

    public function test_saving_unchanged_address_keeps_index(): void
    {
        $order = $this->order('courier');
        $order = $this->update($order, ['delivery_method_id' => $order->delivery_method_id, 'delivery_address' => $this->address()]);

        $this->assertSame('109012', $order->address->postal_code);
        $this->assertSame('109012', $order->delivery_data['destination']['index']);
    }

    /** Окно доставки присылает страну кодом, а в заказе с сайта она названием (заказ 42120). */
    public function test_country_code_instead_of_name_is_not_an_address_change(): void
    {
        \App\Models\Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия']);

        foreach (['office', 'courier'] as $type) {
            $order = $this->update($this->order($type), ['delivery_address' => $this->address(['country' => 'RU'])]);

            $this->assertSame('109012', $order->address->postal_code, $type);
            $this->assertSame('109012', $order->delivery_data['destination']['index'], $type);
        }
        $this->assertSame('109012', $this->update($this->order('office'), ['delivery_address' => $this->address(['country' => 'RU'])])->delivery_data['pvz']['code']);
    }

    public function test_real_country_change_still_clears_stale_index(): void
    {
        \App\Models\Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия']);
        $order = $this->update($this->order('office'), ['delivery_address' => $this->address(['country' => 'KZ'])]);

        $this->assertNull($order->address->postal_code);
        $this->assertArrayNotHasKey('pvz', $order->delivery_data);
    }

    public function test_courier_city_change_finds_new_index(): void
    {
        $order = $this->update($this->order('courier'), [
            'delivery_address' => $this->address(['city' => 'Новосибирск', 'address' => 'Красный проспект 25']),
        ]);

        $this->assertSame('630099', $order->address->postal_code);
        $this->assertSame('630099', $order->delivery_data['destination']['index']);
        $this->assertSame('Новосибирск', $order->delivery_data['destination']['city']);
        $this->assertSame('ONLINE_PARCEL', $order->delivery_data['tariff_code']);
        $this->assertSame(400.0, (float) $order->delivery_cost);
    }

    public function test_office_address_change_clears_stale_office(): void
    {
        $order = $this->update($this->order('office'), ['delivery_address' => $this->address(['city' => 'Новосибирск'])]);

        $this->assertNull($order->address->postal_code);
        $this->assertArrayNotHasKey('pvz', $order->delivery_data);
        $this->assertArrayNotHasKey('index', $order->delivery_data['destination']);
    }

    public function test_manager_index_wins(): void
    {
        $order = $this->update($this->order('office'), [
            'delivery_address' => $this->address(['city' => 'Новосибирск', 'address' => 'Советская ул, 33', 'postal_code' => '630099']),
        ]);

        $this->assertSame('630099', $order->address->postal_code);
        $this->assertSame(['code' => '630099', 'address' => 'Советская ул, 33'], $order->delivery_data['pvz']);
    }

    public function test_paid_message_names_post_office_and_index(): void
    {
        $message = app(OrderMessageBuilder::class)->buildOrderPaid($this->order('office'))['message'];

        $this->assertStringContainsString('Отделение Почты России: Москва, Никольская ул, 7-9, индекс 109012', $message);
        $this->assertStringContainsString('Адрес доставки: ', app(OrderMessageBuilder::class)->buildOrderPaid($this->order('courier'))['message']);
    }

    public function test_post_track_is_shown_to_customer_and_pushed_alone_to_moysklad(): void
    {
        $order = $this->order('office', ['moysklad_order_uuid' => 'ms-1']);
        $this->assertNull($order->customerTrackingNumber());

        $order = $this->update($order, ['custom_fields' => ['russian_post_tracking_number' => '80080012345678']]);

        $this->assertSame('80080012345678', $order->customerTrackingNumber());
        Bus::assertDispatched(PushOrderTracksToMoySkladJob::class);
        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }
}
