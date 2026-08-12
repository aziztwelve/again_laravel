<?php

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyYandexDeliveryCreatedJob;
use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Notifications\YandexDeliveryNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class NotifyYandexDeliveryCreatedJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_sends_the_created_notification_after_reloading_the_order(): void
    {
        $yandexOrder = YandexOrder::create([
            'order_id' => Order::factory()->create()->id,
            'claim_id' => 'request-123',
            'status' => 'created',
            'internal_status' => 'created',
            'customer_status' => 'delivery_created',
        ]);
        $service = Mockery::mock(YandexDeliveryNotificationService::class);
        $service->shouldReceive('notify')
            ->once()
            ->withArgs(fn (YandexOrder $order, string $status) => $order->is($yandexOrder) && $status === 'delivery_created');

        (new NotifyYandexDeliveryCreatedJob($yandexOrder->id))->handle($service);
    }
}
