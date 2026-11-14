<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\Client;
use App\Models\DeliveryMethod;
use App\Models\GiftCard\GiftCard;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\UserProfile;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\OrderMessageText;
use App\Services\Notifications\OrderNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Письмо «Оплата получена»: когда уходит и что в нём написано. */
class OrderPaidNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'order_notifications.paid.enabled' => true,
            'order_notifications.paid.max_paid_age_minutes' => 120,
            'order_notifications.paid.max_order_age_days' => 7,
        ]);
        Bus::fake([SendNotificationJob::class, SyncOrderToMoySkladJob::class]);
    }

    private function method(string $code, string $name): DeliveryMethod
    {
        return DeliveryMethod::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }

    private function order(array $overrides = [], bool $pickup = true): Order
    {
        $client = Client::create(['email' => 'buyer'.uniqid().'@example.com']);
        UserProfile::create(['client_id' => $client->id, 'first_name' => 'Анна']);
        $method = $pickup ? $this->method('cdek_pickup', 'Пункт выдачи СДЭК') : $this->method('cdek_courier', 'Курьер СДЭК');

        $order = Order::factory()->create(array_merge([
            'client_id' => $client->id, 'status' => 'new', 'payment_status' => 'pending', 'paid_at' => null,
            'total_amount' => 7580, 'created_at' => now()->subMinutes(30), 'view_token' => bin2hex(random_bytes(16)),
            'delivery_method_id' => $method->id, 'moysklad_order_uuid' => null,
            'delivery_data' => $pickup
                ? ['provider' => 'cdek', 'delivery_type' => 'pickup', 'pvz' => ['code' => 'ZHKV5', 'address' => 'ул. Молодежная, 22']]
                : ['provider' => 'cdek', 'delivery_type' => 'courier'],
        ], $overrides));
        $order->address()->create(['city' => 'Жуковский', 'address' => $pickup ? 'ул. Молодежная, 22' : 'ул. Мира, 5, кв. 12', 'recipient_first_name' => 'Анна']);

        return $order->fresh();
    }

    private function pay(Order $order, array $extra = []): void
    {
        $order->update(array_merge(['payment_status' => 'paid'], $extra));
    }

    /** @return array{message:string, data:array} */
    private function sent(): array
    {
        $job = Bus::dispatched(SendNotificationJob::class)->first();
        $this->assertNotNull($job, 'письмо не поставлено в очередь');
        $reflection = new \ReflectionClass($job);

        return [
            'message' => $reflection->getProperty('message')->getValue($job),
            'data' => $reflection->getProperty('data')->getValue($job),
        ];
    }

    public function test_paying_an_order_queues_one_email_with_amount_delivery_and_link(): void
    {
        $order = $this->order();

        $this->pay($order);

        Bus::assertDispatched(SendNotificationJob::class, 1);
        $sent = $this->sent();
        $number = $order->order_number;
        $this->assertSame("Оплата получена — заказ №{$number}", $sent['data']['subject']);
        $this->assertSame('order_paid', $sent['data']['type']);
        $this->assertStringContainsString('Здравствуйте, Анна!', $sent['message']);
        $this->assertStringContainsString("Мы получили оплату заказа №{$number} на сумму 7 580 ₽. Спасибо!", $sent['message']);
        $this->assertStringContainsString('Доставка: Пункт выдачи СДЭК', $sent['message']);
        $this->assertStringContainsString('Пункт выдачи: Жуковский, ул. Молодежная, 22', $sent['message']);
        $this->assertStringContainsString('пришлём номер для отслеживания', $sent['message']);
        $this->assertStringContainsString('Открыть заказ: ', $sent['message']);
        $this->assertStringContainsString('/orders/'.$order->view_token, $sent['message']);
        $this->assertStringContainsString('>Открыть заказ</a>', $sent['data']['html']);
        $this->assertDatabaseHas('notification_dispatches', ['event_key' => 'order_paid', 'entity_id' => $order->id, 'channel' => 'email']);
    }

    public function test_courier_delivery_shows_the_address_instead_of_a_pickup_point(): void
    {
        $order = $this->order(pickup: false);

        $this->pay($order);

        $message = $this->sent()['message'];
        $this->assertStringContainsString('Доставка: '.$order->deliveryMethod->name, $message);
        $this->assertStringContainsString('Адрес доставки: Жуковский, ул. Мира, 5, кв. 12', $message);
        $this->assertStringNotContainsString('Пункт выдачи:', $message);
    }

    public function test_kopecks_are_shown_only_when_there_are_any(): void
    {
        $this->assertSame('7 580 ₽', OrderMessageText::money(7580.0));
        $this->assertSame('6,04 ₽', OrderMessageText::money(6.04));
    }

    public function test_the_email_is_sent_only_once_per_order(): void
    {
        $order = $this->order();

        $this->pay($order);
        $order->update(['payment_status' => 'pending']);
        $this->pay($order->fresh());

        Bus::assertDispatched(SendNotificationJob::class, 1);
        $this->assertSame(1, NotificationDispatch::where('event_key', 'order_paid')->where('entity_id', $order->id)->count());
    }

    public function test_manual_payment_mark_without_a_date_also_sends_the_email(): void
    {
        $order = $this->order();

        // Менеджер в админке ставит «Оплачен» и не заполняет дату оплаты.
        $this->pay($order, ['paid_at' => null]);

        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_order_without_an_amount_gets_no_email(): void
    {
        $this->pay($this->order(['total_amount' => 0]));

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_old_payment_date_does_not_trigger_the_email(): void
    {
        $this->pay($this->order(), ['paid_at' => now()->subHours(3)]);

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_old_orders_get_no_email_when_they_are_marked_as_paid_later(): void
    {
        $this->pay($this->order(['created_at' => now()->subDays(8)]));

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_gift_card_purchases_have_their_own_messages(): void
    {
        $order = $this->order();
        GiftCard::create(['code' => 'T'.strtoupper(substr(uniqid(), -9)), 'purchase_order_id' => $order->id, 'nominal' => 3000, 'balance' => 3000, 'type' => 'electronic', 'status' => 'inactive']);

        $this->pay($order);

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_feature_can_be_switched_off(): void
    {
        config(['order_notifications.paid.enabled' => false]);

        $this->pay($this->order());

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_a_failure_while_queueing_the_email_does_not_break_the_payment(): void
    {
        $service = $this->mock(OrderNotificationService::class);
        $service->shouldReceive('notifyOrderPaid')->once()->andThrow(new \RuntimeException('почта недоступна'));
        // once(): слушатель должен сработать ровно один раз (см. комментарий в NotifyCustomerOrderPaid).
        $order = $this->order();

        $this->pay($order);

        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_telegram_text_is_escaped_for_html_mode(): void
    {
        $this->assertSame('a &amp; b &lt;c&gt;', OrderMessageText::forChannel('telegram', 'a & b <c>'));
        $this->assertSame('a & b <c>', OrderMessageText::forChannel('email', 'a & b <c>'));
    }
}
