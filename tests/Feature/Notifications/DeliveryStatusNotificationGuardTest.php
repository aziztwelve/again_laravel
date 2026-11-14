<?php

namespace Tests\Feature\Notifications;

use App\Models\CdekOrder;
use App\Models\DeliveryMethod;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Delivery\Yandex\CustomerStatusMapper;
use App\Services\Delivery\YandexDeliveryService;
use App\Services\Notifications\DeliveryNotificationGuard;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Уведомления покупателю о ходе доставки: реальные статусы Яндекс.Доставки и СДЭК
 * и защита от запоздалых сообщений по старым заказам.
 */
class DeliveryStatusNotificationGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00', 'Europe/Moscow'));
        config([
            'delivery_notifications.not_before' => '2026-11-14 07:45:00 +03:00',
            'delivery_notifications.max_age_hours' => 24,
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        Bus::fake([SendNotificationJob::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- защита

    public function test_guard_allows_only_fresh_statuses_after_the_enable_moment(): void
    {
        $this->assertTrue(DeliveryNotificationGuard::allows(now()->subMinutes(10)));
        $this->assertTrue(DeliveryNotificationGuard::allows(now()->subMinutes(10)->timestamp));
        $this->assertFalse(DeliveryNotificationGuard::allows('2026-11-09T09:18:44+0000'), 'до момента включения');
        $this->assertFalse(DeliveryNotificationGuard::allows(null), 'время неизвестно');
        $this->assertFalse(DeliveryNotificationGuard::allows('не дата'));

        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'Europe/Moscow'));
        $this->assertFalse(DeliveryNotificationGuard::allows('2026-11-15T12:00:00+0300'), 'старше max_age_hours');
        $this->assertTrue(DeliveryNotificationGuard::allows('2026-11-20T11:00:00+0300'));
    }

    // ---- соответствие реальных статусов Яндекса

    public function test_real_yandex_platform_statuses_map_to_customer_statuses(): void
    {
        $mapper = new CustomerStatusMapper;

        $this->assertSame('handed_over', $mapper->toCustomer('SORTING_CENTER_TRANSMITTED'));
        $this->assertSame('ready_for_pickup', $mapper->toCustomer('DELIVERY_ARRIVED_PICKUP_POINT'));
        $this->assertSame('in_transit', $mapper->toCustomer('DELIVERY_TRANSPORTATION'));
        $this->assertSame('delivered', $mapper->toCustomer('DELIVERY_DELIVERED'));
        $this->assertSame('delivery_created', $mapper->toCustomer('SORTING_CENTER_LOADED'));
        $this->assertNull($mapper->toCustomer('DELIVERY_TIME_INTERVALS_UPDATED'), 'статус без соответствия не меняет покупательский');
    }

    // ---- Яндекс: полный путь через опрос

    private function yandexOrder(): YandexOrder
    {
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com', 'moysklad_order_uuid' => null, 'tracking_number' => null,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'pvz' => ['id' => 'p1', 'address' => 'Москва, Тверская, 1']]]);

        return YandexOrder::create([
            'order_id' => $order->id, 'claim_id' => 'claim-'.$order->id.'-udp', 'status' => 'SORTING_CENTER_LOADED', 'internal_status' => 'created',
            'customer_status' => 'delivery_created', 'delivery_type' => 'pickup', 'request_id' => (string) Str::uuid(),
        ]);
    }

    private function pollYandex(YandexOrder $yandexOrder, string $status, int|string|null $at): void
    {
        $state = ['status' => $status, 'description' => $status];
        if ($at !== null) {
            $state['timestamp_unix'] = $at;
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://yandex.test/api/b2b/platform/request/info*' => Http::response([
            'request_id' => $yandexOrder->claim_id, 'state' => $state, 'courier_order_id' => '1077128001', 'sharing_url' => 'https://dostavka.yandex.ru/route/x',
        ])]);

        app(YandexDeliveryService::class)->sync($yandexOrder->fresh());
    }

    private function dispatches(string $eventKey): int
    {
        return NotificationDispatch::where('event_key', $eventKey)->count();
    }

    public function test_yandex_handed_over_is_sent_for_a_fresh_status(): void
    {
        $y = $this->yandexOrder();

        $this->pollYandex($y, 'SORTING_CENTER_TRANSMITTED', now()->subMinutes(10)->timestamp);

        $this->assertSame(1, $this->dispatches('yandex_delivery.handed_over'));
        $this->assertSame('handed_over', $y->fresh()->customer_status);
    }

    public function test_yandex_ready_for_pickup_is_sent_for_a_fresh_status(): void
    {
        $y = $this->yandexOrder();

        $this->pollYandex($y, 'DELIVERY_ARRIVED_PICKUP_POINT', now()->subMinutes(10)->timestamp);

        $this->assertSame(1, $this->dispatches('yandex_delivery.ready_for_pickup'));
    }

    public function test_old_orders_are_updated_silently_without_notifying_customers(): void
    {
        $y = $this->yandexOrder();

        // статус случился ДО включения уведомлений
        $this->pollYandex($y, 'DELIVERY_ARRIVED_PICKUP_POINT', Carbon::parse('2026-11-11 11:11:36 UTC')->timestamp);

        $this->assertSame(0, NotificationDispatch::where('event_key', 'like', 'yandex_delivery.%')->count());
        $this->assertSame('ready_for_pickup', $y->fresh()->customer_status, 'статус для покупателя всё равно обновляется');
    }

    public function test_status_older_than_max_age_is_not_sent_even_after_the_enable_moment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'Europe/Moscow'));
        $y = $this->yandexOrder();

        $this->pollYandex($y, 'SORTING_CENTER_TRANSMITTED', Carbon::parse('2026-11-15 10:00:00', 'Europe/Moscow')->timestamp);

        $this->assertSame(0, NotificationDispatch::where('event_key', 'like', 'yandex_delivery.%')->count());
    }

    public function test_statuses_that_are_not_designed_for_notification_are_not_sent(): void
    {
        foreach (['DELIVERY_TRANSPORTATION', 'DELIVERY_DELIVERED', 'CANCELLED'] as $status) {
            $y = $this->yandexOrder();
            $this->pollYandex($y, $status, now()->subMinutes(5)->timestamp);
        }

        $this->assertSame(0, NotificationDispatch::where('event_key', 'like', 'yandex_delivery.%')->count());
    }

    public function test_repeated_poll_does_not_duplicate_the_notification(): void
    {
        $y = $this->yandexOrder();

        $this->pollYandex($y, 'SORTING_CENTER_TRANSMITTED', now()->subMinutes(10)->timestamp);
        $this->pollYandex($y, 'SORTING_CENTER_TRANSMITTED', now()->subMinutes(10)->timestamp);

        $this->assertSame(1, $this->dispatches('yandex_delivery.handed_over'));
    }

    public function test_status_without_time_is_not_sent(): void
    {
        $y = $this->yandexOrder();

        $this->pollYandex($y, 'SORTING_CENTER_TRANSMITTED', null);

        $this->assertSame(0, $this->dispatches('yandex_delivery.handed_over'));
    }

    // ---- СДЭК

    private function cdekService(): CdekDeliveryService
    {
        return new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
            'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'default_package' => ['weight' => 80, 'length' => 26, 'width' => 21, 'height' => 4],
        ]);
    }

    private function cdekOrder(): CdekOrder
    {
        $method = DeliveryMethod::firstOrCreate(['code' => 'cdek_pickup'], ['name' => 'cdek-'.Str::uuid(), 'is_active' => true]);
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com', 'moysklad_order_uuid' => null, 'delivery_method_id' => $method->id,
            'delivery_data' => ['provider' => 'cdek', 'delivery_type' => 'pickup', 'pvz' => ['code' => 'MSK171']]]);

        return CdekOrder::create(['order_id' => $order->id, 'external_order_number' => 'order-'.$order->id, 'delivery_type' => 'pickup', 'tariff_code' => 136,
            'creation_state' => 'SUCCESSFUL', 'status_code' => 'SENT_TO_RECIPIENT_CITY']);
    }

    private function pollCdek(CdekOrder $cdekOrder, string $code, string $at): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/orders*' => Http::response(['entity' => [
                'uuid' => 'cdek-uuid-1', 'cdek_number' => '10337704825',
                'statuses' => [['code' => $code, 'name' => $code, 'date_time' => $at]],
            ], 'requests' => [['type' => 'CREATE', 'state' => 'SUCCESSFUL']]]),
        ]);

        $this->cdekService()->sync($cdekOrder->fresh());
    }

    public function test_cdek_ready_for_pickup_uses_the_real_status_code_and_is_sent_when_fresh(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'ACCEPTED_AT_PICK_UP_POINT', now()->subMinutes(10)->format('Y-m-d\TH:i:sO'));

        $this->assertSame(1, $this->dispatches('cdek_delivery.ready_for_pickup'));
    }

    public function test_cdek_old_status_change_is_not_sent(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'ACCEPTED_AT_PICK_UP_POINT', '2026-11-09T09:18:44+0000');

        $this->assertSame(0, $this->dispatches('cdek_delivery.ready_for_pickup'));
        $this->assertSame('ACCEPTED_AT_PICK_UP_POINT', $c->fresh()->status_code, 'статус заявки при этом обновляется');
    }

    public function test_cdek_delivered_keeps_working_for_fresh_status(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'DELIVERED', now()->subMinutes(10)->format('Y-m-d\TH:i:sO'));

        $this->assertSame(1, $this->dispatches('cdek_delivery.delivered'));
    }

    public function test_cdek_transit_statuses_are_still_not_sent(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'ACCEPTED_AT_TRANSIT_WAREHOUSE', now()->subMinutes(10)->format('Y-m-d\TH:i:sO'));

        $this->assertSame(0, NotificationDispatch::where('event_key', 'like', 'cdek_delivery.%')->count());
    }
}
