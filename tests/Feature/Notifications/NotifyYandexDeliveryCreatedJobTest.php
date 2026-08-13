<?php

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyYandexDeliveryCreatedJob;
use App\Models\Order;
use App\Models\YandexOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifyYandexDeliveryCreatedJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_does_not_send_the_disabled_created_notification(): void
    {
        $yandexOrder = YandexOrder::create([
            'order_id' => Order::factory()->create()->id,
            'claim_id' => 'request-123',
            'status' => 'created',
            'internal_status' => 'created',
            'customer_status' => 'delivery_created',
            'delivery_type' => 'courier',
            'request_id' => (string) Str::uuid(),
        ]);
        $this->assertNull((new NotifyYandexDeliveryCreatedJob($yandexOrder->id))->handle());
    }
}
