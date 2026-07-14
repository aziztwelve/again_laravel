<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\GiftCard\GiftCard;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\UserProfile;
use App\Services\Notifications\GiftCardMessageBuilder;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Notifications\OrderMessageBuilder;
use App\Services\Notifications\OrderNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OrderNotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // ConversationService резолвится на каждом запросе и требует строку
        // mail_settings — оставляем как в остальных тестах проекта.
        \App\Models\MailSetting::create([
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);
    }

    private function clientWithChannels(array $overrides = []): Client
    {
        $client = Client::create(['email' => $overrides['email'] ?? 'client@example.com']);

        UserProfile::create(array_merge([
            'client_id' => $client->id,
            'first_name' => 'Евгения',
            'last_name' => 'Иванова',
            'phone' => '7(923)418-84-94',
            'telegram_user_id' => '111111',
            'max_user_id' => '222222',
        ], $overrides['profile'] ?? []));

        return $client->fresh('profile');
    }

    public function test_order_created_dispatches_all_customer_channels(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $client = $this->clientWithChannels();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'total_amount' => 3000,
        ]);

        app(OrderNotificationService::class)->notifyOrderCreated($order, $client);

        // Email + Telegram + MAX — три канала (решение #1).
        Bus::assertDispatched(SendNotificationJob::class, 3);
    }

    public function test_order_created_email_only_for_guest(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $order = Order::factory()->create([
            'client_id' => null,
            'email' => 'guest@example.com',
            'total_amount' => 3000,
        ]);

        app(OrderNotificationService::class)->notifyOrderCreated($order, null);

        // У гостя нет профиля с telegram/max — только email.
        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_order_notification_is_idempotent_per_channel(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $client = $this->clientWithChannels();
        $order = Order::factory()->create(['client_id' => $client->id, 'total_amount' => 3000]);
        $service = app(OrderNotificationService::class);

        $service->notifyOrderCreated($order, $client);
        $service->notifyOrderCreated($order, $client);

        Bus::assertDispatched(SendNotificationJob::class, 3);
    }

    public function test_order_created_includes_vk_when_linked(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $client = $this->clientWithChannels([
            'profile' => ['vk_user_id' => '333333'],
        ]);
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'total_amount' => 3000,
        ]);

        app(OrderNotificationService::class)->notifyOrderCreated($order, $client);

        Bus::assertDispatched(SendNotificationJob::class, 4);
    }

    public function test_order_message_matches_reference_format(): void
    {
        $client = $this->clientWithChannels();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'order_number' => '35153',
            'payment_method' => 'card_ru',
            'total_amount' => 3000,
        ]);

        $product = Product::create([
            'name' => 'Подарочный сертификат',
            'is_active' => true,
            'price' => 3000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 3000,
            'is_gift' => false,
        ]);

        $message = app(OrderMessageBuilder::class)->buildOrderCreated($order->fresh());

        $this->assertStringContainsString('Новый заказ №35153', $message);
        $this->assertStringContainsString('Магазин again8.ru', $message);
        $this->assertStringContainsString('Клиент: Иванова Евгения (7(923)418-84-94)', $message);
        $this->assertStringContainsString('Способ оплаты: Оплата картой РФ', $message);
        $this->assertStringContainsString('Состав заказа:', $message);
        $this->assertStringContainsString('Подарочный сертификат', $message);
    }

    public function test_order_message_uses_current_recipient_instead_of_stale_client_profile(): void
    {
        $client = $this->clientWithChannels();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'total_amount' => 1000,
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_first_name' => 'Азизжон',
            'recipient_last_name' => 'Каримов',
            'recipient_phone' => '+7 (895) 236-21-86',
        ]);

        $message = app(OrderMessageBuilder::class)->buildOrderCreated($order->fresh());

        $this->assertStringContainsString('Клиент: Азизжон Каримов (+7 (895) 236-21-86)', $message);
        $this->assertStringNotContainsString('Клиент: Иванова Евгения', $message);
    }

    public function test_gift_card_delivery_message_matches_reference(): void
    {
        $card = new GiftCard([
            'code' => 'D8JNWKA3K8DS',
            'nominal' => 10000,
            'recipient_name' => 'Антон',
        ]);
        $card->sent_at = now()->setDate(2026, 2, 19)->setTime(9, 38);

        $message = app(GiftCardMessageBuilder::class)->buildDeliveryConfirmation($card);

        $this->assertStringContainsString('Ваша подарочная карта успешно доставлена!', $message);
        $this->assertStringContainsString('Получатель: Антон', $message);
        $this->assertStringContainsString('Номинал: 10000.00 ₽', $message);
        $this->assertStringContainsString('Код: D8JNWKA3K8DS', $message);
        $this->assertStringContainsString('Доставлено: 19.02.2026 09:38', $message);
        // По ТЗ строки «Заказ #...» быть не должно.
        $this->assertStringNotContainsString('Заказ #', $message);
    }
}
