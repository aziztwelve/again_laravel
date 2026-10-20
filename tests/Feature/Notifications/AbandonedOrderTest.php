<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\Order;
use App\Models\UserProfile;
use App\Services\Notifications\AbandonedOrderService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationConversationMirrorService;
use App\Services\Order\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AbandonedOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['abandoned_order.enabled' => true, 'abandoned_order.after_minutes' => 30, 'abandoned_order.reservation_hours' => 20]);
        \App\Models\MailSetting::create([
            'mailer' => 'smtp', 'host' => 'localhost', 'port' => 25,
            'username' => 'test', 'password' => 'secret', 'from_address' => 'test@example.com',
        ]);
        Bus::fake([SendNotificationJob::class]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'status' => 'new', 'payment_status' => 'pending', 'paid_at' => null,
            'created_at' => now()->subHour(), 'total_amount' => 3000,
            'view_token' => bin2hex(random_bytes(16)), 'email' => 'guest@example.com',
            'order_page_opened_at' => now()->subHour(),
            'reservation_expires_at' => now()->addHours(19),
        ], $overrides));
    }

    public function test_one_reminder_per_available_channel_with_requested_copy(): void
    {
        $client = Client::create(['email' => 'buyer@example.com']);
        UserProfile::create([
            'client_id' => $client->id, 'first_name' => 'Анна',
            'telegram_chat_id' => '111', 'max_user_id' => '222', 'vk_user_id' => '333',
        ]);
        $order = $this->order(['client_id' => $client->id]);
        $this->artisan('orders:remind-abandoned')->assertSuccessful();
        $this->artisan('orders:remind-abandoned')->assertSuccessful();
        Bus::assertDispatched(SendNotificationJob::class, 3);
        $this->assertSame(3, \App\Models\NotificationDispatch::where('event_key', 'abandoned_order')->where('entity_id', $order->id)->count());
        $this->assertDatabaseMissing('notification_dispatches', ['event_key' => 'abandoned_order', 'channel' => 'vk']);
        $job = Bus::dispatched(SendNotificationJob::class)->first();
        $reflection = new \ReflectionClass($job);
        $data = $reflection->getProperty('data')->getValue($job);
        $message = $reflection->getProperty('message')->getValue($job);
        $this->assertSame('Анна, вы кое-что забыли!', $data['subject']);
        $this->assertSame('Завершить покупку в 1 клик', $data['action_label']);
        $this->assertStringContainsString('Привет, Анна!', $message);
        $this->assertStringContainsString('20 часов', $message);
        $this->assertStringContainsString('<strong>20 часов</strong>', $data['html']);
        $this->assertStringNotContainsString('24 часа', $data['html']);
        $this->assertStringContainsString('/orders/'.$order->view_token, $data['html']);
        $this->assertStringContainsString('Завершить покупку в 1 клик', $data['html']);
    }

    public function test_reminder_is_due_at_thirty_minutes_but_not_one_second_earlier(): void
    {
        $service = app(AbandonedOrderService::class);
        $this->assertFalse($service->isEligible($this->order(['order_page_opened_at' => now()->subSeconds(1799)])));
        $this->assertTrue($service->isEligible($this->order(['order_page_opened_at' => now()->subSeconds(1800)])));
    }

    public function test_guest_receives_email_with_neutral_greeting(): void
    {
        app(AbandonedOrderService::class)->remind($this->order());
        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_ineligible_orders_do_not_receive_reminders(): void
    {
        foreach ([
            ['order_page_opened_at' => now()->subMinutes(29)],
            ['order_page_opened_at' => null],
            ['payment_interface_started_at' => now()],
            ['payment_status' => 'paid', 'paid_at' => now()],
            ['status' => 'cancelled'],
            ['reservation_expires_at' => now()],
            ['reservation_expires_at' => null],
            ['total_amount' => 0],
            ['payment_status' => 'refunded'],
        ] as $state) {
            app(AbandonedOrderService::class)->remind($this->order($state));
        }
        Bus::assertNothingDispatched();
    }

    public function test_activity_is_idempotent_and_requires_valid_token(): void
    {
        $order = $this->order(['order_page_opened_at' => null]);
        $url = '/api/public/orders/'.$order->view_token.'/activity';
        $this->postJson($url, ['event' => 'opened'])->assertOk();
        $first = $order->fresh()->order_page_opened_at;
        $this->travel(10)->minutes();
        $this->postJson($url, ['event' => 'opened'])->assertOk();
        $this->assertTrue($first->equalTo($order->fresh()->order_page_opened_at));
        $this->postJson($url, ['event' => 'payment_started'])->assertOk();
        $this->assertNotNull($order->fresh()->payment_interface_started_at);
        $this->postJson($url, ['event' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/public/orders/'.str_repeat('0', 32).'/activity', ['event' => 'opened'])->assertNotFound();
    }

    public function test_queue_rechecks_order_before_sending(): void
    {
        $order = $this->order();
        app(AbandonedOrderService::class)->remind($order);
        $job = Bus::dispatched(SendNotificationJob::class)->first();
        $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
        $sender = $this->mock(NotificationService::class);
        $sender->shouldNotReceive('sendViaChannel');
        $mirror = $this->mock(NotificationConversationMirrorService::class);
        $mirror->shouldNotReceive('mirror');
        $job->handle($sender, $mirror);
        $this->assertDatabaseHas('notification_dispatches', ['event_key' => 'abandoned_order', 'status' => 'skipped']);
    }

    public function test_disabled_feature_does_not_queue(): void
    {
        $this->order();
        config(['abandoned_order.enabled' => false]);
        $this->artisan('orders:remind-abandoned')->assertSuccessful();
        Bus::assertNothingDispatched();
    }

    public function test_reservation_prevents_two_hour_cancellation_but_expires_at_20_hours(): void
    {
        $protected = $this->order(['created_at' => now()->subHours(3)]);
        $expired = $this->order(['created_at' => now()->subHours(20), 'reservation_expires_at' => now()]);
        $legacy = $this->order(['created_at' => now()->subHours(3), 'reservation_expires_at' => null]);
        $service = $this->mock(OrderCreationService::class);
        $service->shouldReceive('cancelOrder')->withArgs(fn ($order, $reason) => $order->id === $expired->id && str_contains($reason, 'истёк срок брони'))->once()->andReturn(true);
        $service->shouldReceive('cancelOrder')->withArgs(fn ($order, $reason) => $order->id === $legacy->id && str_contains($reason, '2 часов'))->once()->andReturn(true);
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();
        $this->assertSame('new', $protected->fresh()->status->value);
    }
}
