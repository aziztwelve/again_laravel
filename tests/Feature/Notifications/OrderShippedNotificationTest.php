<?php

namespace Tests\Feature\Notifications;

use App\Models\CdekOrder;
use App\Models\DeliveryMethod;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\OrderMessageText;
use App\Services\Notifications\YandexDeliveryMessageBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Письмо «Заказ отправлен»: когда уходит у СДЭК и Яндекса и что в нём написано. */
class OrderShippedNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00', 'Europe/Moscow'));
        config(['delivery_notifications.not_before' => '2026-11-14 07:45:00 +03:00', 'delivery_notifications.max_age_hours' => 24]);
        Bus::fake([SendNotificationJob::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cdekService(): CdekDeliveryService
    {
        return new CdekDeliveryService([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
            'sender' => ['city_code' => 44, 'address' => 'Москва'],
            'default_package' => ['weight' => 80, 'length' => 26, 'width' => 21, 'height' => 4],
        ]);
    }

    private function cdekOrder(string $type = 'pickup'): CdekOrder
    {
        $method = DeliveryMethod::firstOrCreate(['code' => 'cdek_'.$type], ['name' => 'cdek-'.Str::uuid(), 'is_active' => true]);
        $delivery = ['provider' => 'cdek', 'delivery_type' => $type, 'period' => ['min' => 4, 'max' => 5]];
        if ($type === 'courier') {
            $delivery['destination'] = ['city' => 'Москва', 'address' => 'Арбат, 10'];
        } else {
            $delivery['pvz'] = ['code' => 'ZHKV5', 'address' => 'ул. Молодежная, 22'];
        }
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com', 'moysklad_order_uuid' => null,
            'delivery_method_id' => $method->id, 'delivery_data' => $delivery, 'status' => 'processing']);
        $order->address()->create(['city' => $type === 'courier' ? 'Москва' : 'Жуковский', 'address' => 'Арбат, 10', 'recipient_first_name' => 'Анна']);

        return CdekOrder::create(['order_id' => $order->id, 'external_order_number' => 'order-'.$order->id, 'delivery_type' => $type, 'tariff_code' => 136,
            'creation_state' => 'SUCCESSFUL', 'status_code' => 'CREATED']);
    }

    private function pollCdek(CdekOrder $cdekOrder, string $code, ?string $at = null, string $number = '10346131475'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/v2/orders*' => Http::response(['entity' => [
                'uuid' => 'cdek-uuid-'.$cdekOrder->id, 'cdek_number' => $number,
                'statuses' => [['code' => $code, 'name' => $code, 'date_time' => $at ?? now()->subMinutes(10)->format('Y-m-d\TH:i:sO')]],
            ], 'requests' => [['type' => 'CREATE', 'state' => 'SUCCESSFUL']]]),
        ]);

        $this->cdekService()->sync($cdekOrder->fresh());
    }

    /** @return array{message:string, data:array} */
    private function sent(int $index = 0): array
    {
        $job = Bus::dispatched(SendNotificationJob::class)->values()->get($index);
        $this->assertNotNull($job, 'письмо не поставлено в очередь');
        $reflection = new \ReflectionClass($job);

        return [
            'message' => $reflection->getProperty('message')->getValue($job),
            'data' => $reflection->getProperty('data')->getValue($job),
        ];
    }

    public function test_cdek_shipped_email_has_number_link_address_and_term(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'READY_FOR_SHIPMENT_IN_SENDER_CITY');

        $number = $c->order->order_number;
        $sent = $this->sent();
        $this->assertSame("Заказ №{$number} отправлен — номер для отслеживания 10346131475", $sent['data']['subject']);
        $this->assertSame('cdek_delivery.handed_over', $sent['data']['type']);
        $this->assertStringContainsString('Здравствуйте, Анна!', $sent['message']);
        $this->assertStringContainsString("Ваш заказ №{$number} отправлен.", $sent['message']);
        $this->assertStringContainsString('Служба доставки: СДЭК', $sent['message']);
        $this->assertStringContainsString('Номер для отслеживания: 10346131475', $sent['message']);
        $this->assertStringContainsString('Отследить доставку: https://www.cdek.ru/ru/tracking?order_id=10346131475', $sent['message']);
        $this->assertStringContainsString('Куда приедет: Жуковский, ул. Молодежная, 22', $sent['message']);
        $this->assertStringContainsString('Ориентировочный срок: 4–5 дней', $sent['message']);
        $this->assertStringContainsString('Когда заказ придёт в пункт выдачи', $sent['message']);
        $this->assertStringContainsString('href="https://www.cdek.ru/ru/tracking?order_id=10346131475"', $sent['data']['html']);
        $this->assertStringContainsString('>Отследить доставку</a>', $sent['data']['html']);
    }

    public function test_postamat_and_courier_variants_of_the_text(): void
    {
        $courier = $this->cdekOrder('courier');
        $this->pollCdek($courier, 'READY_FOR_SHIPMENT_IN_SENDER_CITY');
        $text = $this->sent()['message'];
        $this->assertStringContainsString('Адрес доставки: Москва, Арбат, 10', $text);
        $this->assertStringContainsString('Курьер свяжется с вами перед доставкой.', $text);
        $this->assertStringNotContainsString('Когда заказ придёт в', $text);

        $postamat = $this->cdekOrder();
        $postamat->update(['delivery_type' => 'postamat']);
        $this->pollCdek($postamat, 'READY_FOR_SHIPMENT_IN_SENDER_CITY', null, '10346131476');
        $this->assertStringContainsString('Когда заказ придёт в постамат', $this->sent(1)['message']);
    }

    public function test_shipped_email_is_sent_once_even_if_two_mapped_statuses_follow(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'READY_FOR_SHIPMENT_IN_SENDER_CITY');
        $this->pollCdek($c, 'RECEIVED_AT_SHIPMENT_WAREHOUSE');

        $this->assertSame(1, NotificationDispatch::where('event_key', 'cdek_delivery.handed_over')->where('entity_id', $c->id)->count());
    }

    public function test_old_status_change_does_not_send_a_late_shipped_email(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'READY_FOR_SHIPMENT_IN_SENDER_CITY', '2026-11-09T09:18:44+0000');

        $this->assertSame(0, NotificationDispatch::where('event_key', 'cdek_delivery.handed_over')->count());
    }

    public function test_later_transit_statuses_still_do_not_send_anything(): void
    {
        $c = $this->cdekOrder();

        $this->pollCdek($c, 'SENT_TO_TRANSIT_CITY');

        $this->assertSame(0, NotificationDispatch::where('event_key', 'like', 'cdek_delivery.%')->count());
    }

    private function yandexOrder(array $yandex = []): YandexOrder
    {
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com', 'moysklad_order_uuid' => null,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'pvz' => ['id' => 'p1', 'address' => 'Москва, Тверская, 1'],
                'delivery_interval' => ['from' => '2026-11-18T09:00:00+03:00', 'to' => '2026-11-18T21:00:00+03:00']]]);
        $order->address()->create(['city' => 'Москва', 'address' => 'Тверская, 1', 'recipient_first_name' => 'Олег']);

        return YandexOrder::create(array_merge([
            'order_id' => $order->id, 'claim_id' => 'claim-'.$order->id.'-int', 'status' => 'SORTING_CENTER_TRANSMITTED', 'internal_status' => 'picked_up',
            'customer_status' => 'handed_over', 'delivery_type' => 'pickup', 'request_id' => (string) Str::uuid(),
            'tracking_number' => 'YA68DA61EE6B6F9BEFD', 'tracking_url' => 'https://dostavka.yandex.ru/route/abc123',
        ], $yandex));
    }

    public function test_yandex_shipped_email_has_number_link_address_and_expected_date(): void
    {
        $y = $this->yandexOrder();

        $content = app(YandexDeliveryMessageBuilder::class)->build($y, 'handed_over');

        $number = $y->order->order_number;
        $this->assertSame("Заказ №{$number} отправлен — номер для отслеживания YA68DA61EE6B6F9BEFD", $content['subject']);
        $this->assertStringContainsString('Здравствуйте, Олег!', $content['message']);
        $this->assertStringContainsString('Служба доставки: Яндекс Доставка', $content['message']);
        $this->assertStringContainsString('Номер для отслеживания: YA68DA61EE6B6F9BEFD', $content['message']);
        $this->assertStringContainsString('Отследить доставку: https://dostavka.yandex.ru/route/abc123', $content['message']);
        $this->assertStringContainsString('Куда приедет: Москва, Тверская, 1', $content['message']);
        $this->assertStringContainsString('Ожидаемая доставка: 18 ноября', $content['message']);
        $this->assertStringContainsString('href="https://dostavka.yandex.ru/route/abc123"', $content['html']);
    }

    public function test_yandex_without_a_partner_number_shows_the_link_and_never_the_internal_claim_id(): void
    {
        $y = $this->yandexOrder(['tracking_number' => null]);

        $content = app(YandexDeliveryMessageBuilder::class)->build($y, 'handed_over');

        $this->assertSame('Заказ №'.$y->order->order_number.' отправлен', $content['subject']);
        $this->assertStringNotContainsString('Номер для отслеживания', $content['message']);
        $this->assertStringNotContainsString($y->claim_id, $content['message']);
        $this->assertStringContainsString('Отследить доставку: https://dostavka.yandex.ru/route/abc123', $content['message']);
    }

    public function test_other_yandex_messages_keep_their_texts(): void
    {
        $y = $this->yandexOrder();

        $content = app(YandexDeliveryMessageBuilder::class)->build($y, 'ready_for_pickup');

        $this->assertStringContainsString('Заказ можно получить', $content['subject']);
        $this->assertStringContainsString('Заказ прибыл в выбранный пункт выдачи.', $content['message']);
    }

    public function test_long_city_value_from_checkout_is_shortened_to_the_city_name(): void
    {
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com', 'moysklad_order_uuid' => null,
            'delivery_data' => ['delivery_type' => 'pickup', 'pvz' => ['address' => 'ул. Молодежная, 22']]]);
        $order->address()->create(['city' => 'Жуковский, городской округ Жуковский, Московская область, Россия', 'address' => 'x']);

        $this->assertSame('Жуковский, ул. Молодежная, 22', OrderMessageText::address($order->fresh(), true));
    }

    public function test_period_and_address_helpers(): void
    {
        $this->assertSame('4–5 дней', OrderMessageText::period(['min' => 4, 'max' => 5]));
        $this->assertSame('2–3 дня', OrderMessageText::period(['min' => 2, 'max' => 3]));
        $this->assertSame('1 день', OrderMessageText::period(['min' => 1, 'max' => 1]));
        $this->assertSame('11–12 дней', OrderMessageText::period(['min' => 11, 'max' => 12]));
        $this->assertNull(OrderMessageText::period(null));
        $this->assertNull(OrderMessageText::period(['min' => 0, 'max' => 0]));
    }
}
