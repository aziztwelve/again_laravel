<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\UserProfile;
use App\Models\YandexOrder;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\YandexDeliveryMessageBuilder;
use App\Services\Notifications\YandexDeliveryNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class YandexDeliveryNotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_dispatches_each_available_channel_once(): void
    {
        Bus::fake([SendNotificationJob::class]);
        [$order, $yandexOrder] = $this->deliveryWithClient(customerStatus: 'handed_over');
        $service = app(YandexDeliveryNotificationService::class);

        $service->notify($yandexOrder);
        $service->notify($yandexOrder);

        Bus::assertDispatched(SendNotificationJob::class, 4);
        $this->assertSame(4, NotificationDispatch::query()
            ->where('entity_type', 'yandex_order')
            ->where('entity_id', $yandexOrder->id)
            ->where('event_key', 'yandex_delivery.handed_over')
            ->count());
    }

    public function test_it_does_not_dispatch_disabled_status_notifications(): void
    {
        Bus::fake([SendNotificationJob::class]);
        [, $yandexOrder] = $this->deliveryWithClient(customerStatus: 'delivery_created');

        foreach (['delivery_created', 'in_transit', 'delivered', 'cancelled', 'returning'] as $status) {
            app(YandexDeliveryNotificationService::class)->notify($yandexOrder, $status);
        }

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_pickup_message_contains_tracking_and_pickup_wording(): void
    {
        [, $yandexOrder] = $this->deliveryWithClient('pickup');

        $content = app(YandexDeliveryMessageBuilder::class)->build(
            $yandexOrder->forceFill(['customer_status' => 'in_transit']),
            'in_transit',
        );

        $this->assertStringContainsString('направляется в выбранный пункт выдачи', $content['message']);
        $this->assertStringContainsString('https://example.test/tracking/123', $content['message']);
        $this->assertStringContainsString('Отследить доставку', $content['html']);
    }

    public function test_guest_receives_email_only(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $order = Order::factory()->create(['client_id' => null, 'email' => 'guest@example.com']);
        $yandexOrder = $this->createYandexOrder($order);

        app(YandexDeliveryNotificationService::class)->notify($yandexOrder);

        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_delivery_interval_is_formatted_for_customer_timezone(): void
    {
        [, $yandexOrder] = $this->deliveryWithClient('pickup');
        $deliveryData = $yandexOrder->order->delivery_data;
        $deliveryData['delivery_interval'] = [
            'from' => '2026-08-16T05:00:00.000000Z',
            'to' => '2026-08-16T14:00:00.000000Z',
        ];
        $yandexOrder->order->delivery_data = $deliveryData;

        $content = app(YandexDeliveryMessageBuilder::class)->build($yandexOrder, 'delivery_created');

        $this->assertStringContainsString('16 августа, 08:00–17:00 (МСК)', $content['message']);
        $this->assertStringNotContainsString('2026-08-16T', $content['message']);
    }

    /** @return array{Order,YandexOrder} */
    private function deliveryWithClient(string $deliveryType = 'courier', string $customerStatus = 'delivery_created'): array
    {
        $client = Client::create(['email' => 'client@example.com']);
        UserProfile::create([
            'client_id' => $client->id,
            'first_name' => 'Тест',
            'telegram_user_id' => '111111',
            'max_user_id' => '222222',
            'vk_user_id' => '333333',
        ]);
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'delivery_data' => [
                'delivery_type' => $deliveryType,
                'pvz' => ['id' => 'pvz-1', 'address' => 'Москва, Тверская, 1'],
            ],
        ]);

        return [$order, $this->createYandexOrder($order, $deliveryType, $customerStatus)];
    }

    private function createYandexOrder(Order $order, string $deliveryType = 'courier', string $customerStatus = 'delivery_created'): YandexOrder
    {
        return YandexOrder::create([
            'order_id' => $order->id,
            'claim_id' => 'request-'.$order->id,
            'status' => 'created',
            'internal_status' => 'created',
            'customer_status' => $customerStatus,
            'delivery_type' => $deliveryType,
            'tracking_number' => 'TRACK-123',
            'tracking_url' => 'https://example.test/tracking/123',
            'request_id' => (string) Str::uuid(),
        ]);
    }
}
