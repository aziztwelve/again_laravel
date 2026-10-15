<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartCommunication;
use App\Models\Client;
use App\Models\MailSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Setting;
use App\Services\Cart\AbandonedCartService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Order\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CartDetailsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        MailSetting::create([
            'mailer' => 'smtp', 'host' => 'localhost', 'port' => 25,
            'username' => 'test', 'password' => 'secret', 'from_address' => 'test@example.com',
        ]);
        Queue::fake();
        config(['abandoned_cart.enabled' => true]);
        Setting::where('key', 'abandoned_cart_settings')->delete();
    }

    private function makeCart(): Cart
    {
        $client = Client::create(['email' => 'cart-details-'.uniqid().'@example.com', 'password' => bcrypt('secret')]);
        UserProfile::create(['client_id' => $client->id, 'first_name' => 'Валерия', 'last_name' => 'Тест', 'phone' => '+79990001122']);
        $product = Product::create([
            'name' => 'Товар для карточки', 'slug' => 'cart-details-'.uniqid(),
            'price' => 1990, 'currency' => 'RUB', 'stock_quantity' => 10,
            'is_active' => true, 'has_variants' => false,
        ]);
        $cart = Cart::create([
            'client_id' => $client->id, 'status' => 'abandoned',
            'created_at' => now()->subDay(), 'updated_at' => now(),
            'total' => 3000, 'total_original' => 3980, 'total_discount' => 980,
            'recovery_token' => bin2hex(random_bytes(16)),
        ]);
        $cart->items()->create([
            'product_id' => $product->id, 'quantity' => 2, 'price' => 1500,
            'price_original' => 1990, 'total' => 3000, 'total_original' => 3980, 'total_discount' => 980,
        ]);

        return $cart;
    }

    private function staff(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    public function test_details_keep_other_orders_separate_and_show_saved_prices_and_communications(): void
    {
        $cart = $this->makeCart();
        $order = Order::create([
            'client_id' => $cart->client_id, 'order_number' => 'DETAILS-'.uniqid(),
            'status' => 'processing', 'payment_status' => 'pending', 'total_amount' => 1000,
        ]);
        CartCommunication::create(['cart_id' => $cart->id, 'channel' => 'email', 'type' => 'trigger', 'step' => 1, 'status' => 'sent', 'sent_at' => now()]);
        $this->staff();
        $response = $this->getJson("/api/carts/{$cart->id}")->assertOk()
            ->assertJsonPath('data.customer.id', $cart->client_id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.price', 1500)
            ->assertJsonPath('data.total_discount', 980)
            ->assertJsonPath('data.communications.0.status', 'sent')
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.from_this_cart', false)
            ->assertJsonPath('data.can_create_order', true)
            ->assertJsonPath('data.available_channels.0', 'email');
        $this->assertArrayNotHasKey('recovery_token', $response->json('data'));
        $this->assertSame('abandoned', $cart->fresh()->status);
    }

    public function test_cart_images_use_the_existing_product_thumbnail_endpoint(): void
    {
        $cart = $this->makeCart();
        $cart->items->first()->product->images()->create(['path' => 'cart-photo.webp', 'is_main' => 1]);
        $this->staff();
        $url = url('/api/product/image/sm_cart-photo.webp');
        $this->getJson("/api/carts/{$cart->id}")->assertOk()->assertJsonPath('data.items.0.image_url', $url);
        $this->getJson('/api/carts/products?search=Товар для карточки')->assertOk()
            ->assertJsonPath('data.data.0.image_url', $url);
    }

    public function test_staff_can_open_the_client_recovery_link_without_sending_or_creating_an_order(): void
    {
        $cart = $this->makeCart();
        $cart->update(['recovery_token' => null]);
        $before = $cart->fresh()->only(['status', 'abandoned_at', 'last_activity_at', 'recovery_cycle']);
        $orders = Order::count();
        $this->staff();
        $url = $this->postJson("/api/carts/{$cart->id}/recovery-link")->assertOk()->json('data.url');
        $token = $cart->fresh()->recovery_token;
        $this->assertNotEmpty($token);
        $this->assertSame(rtrim(config('abandoned_cart.recovery_url'), '/').'/'.$token, $url);
        $this->assertStringNotContainsString('communication=', $url);
        $this->postJson("/api/carts/{$cart->id}/recovery-link")->assertOk()->assertJsonPath('data.url', $url);
        $this->assertEquals($before, $cart->fresh()->only(array_keys($before)));
        $this->assertSame($orders, Order::count());
        $this->assertSame(0, $cart->communications()->count());
        Queue::assertNothingPushed();
    }

    public function test_recovery_link_rejects_ordered_empty_and_guest_carts(): void
    {
        $cart = $this->makeCart();
        $this->staff();
        $cart->update(['status' => 'ordered']);
        $this->postJson("/api/carts/{$cart->id}/recovery-link")->assertStatus(422);
        $cart->update(['status' => 'active']);
        $cart->items()->delete();
        $this->postJson("/api/carts/{$cart->id}/recovery-link")->assertStatus(422);
        $guest = Cart::create(['status' => 'active', 'created_at' => now(), 'guest_token' => bin2hex(random_bytes(16))]);
        $this->postJson("/api/carts/{$guest->id}/recovery-link")->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_client_cannot_read_another_clients_cart_or_draft(): void
    {
        $cart = $this->makeCart();
        $otherClient = Client::create(['email' => 'other-'.uniqid().'@example.com', 'password' => bcrypt('secret')]);
        $this->actingAs($otherClient, 'sanctum');
        $this->getJson("/api/carts/{$cart->id}")->assertForbidden();
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertForbidden();
        $this->postJson("/api/carts/{$cart->id}/recovery-link")->assertForbidden();
        $this->postJson("/api/carts/{$cart->id}/remind", ['channel' => 'email', 'message' => 'Привет'])->assertForbidden();
        $this->postJson("/api/carts/{$cart->id}/remind")->assertForbidden();
        $this->getJson('/api/carts/settings')->assertForbidden();
        $this->getJson('/api/carts/products')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_draft_reprices_without_creating_an_order_or_changing_cart(): void
    {
        $cart = $this->makeCart();
        $this->staff();
        $count = Order::count();
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertOk()
            ->assertJsonPath('data.cart_id', $cart->id)
            ->assertJsonPath('data.customer.id', $cart->client_id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.price', 1990);
        $this->assertSame($count, Order::count());
        $this->assertSame('abandoned', $cart->fresh()->status);
        $this->assertSame(1500.0, (float) $cart->items()->first()->price);
        Queue::assertNothingPushed();
    }

    public function test_unavailable_products_are_visible_but_cannot_be_ordered(): void
    {
        $cart = $this->makeCart();
        $cart->items()->first()->product->delete();
        $this->staff();
        $this->getJson("/api/carts/{$cart->id}")->assertOk()
            ->assertJsonPath('data.items.0.name', 'Товар для карточки')
            ->assertJsonPath('data.items.0.available', false);
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertUnprocessable();
    }

    public function test_draft_rejects_insufficient_stock_and_an_already_ordered_cart(): void
    {
        $cart = $this->makeCart();
        $cart->items()->first()->product->update(['stock_quantity' => 1]);
        $this->staff();
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertUnprocessable();
        $cart->update(['status' => 'ordered']);
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertUnprocessable();
    }

    public function test_manager_message_queues_once_and_is_throttled(): void
    {
        $cart = $this->makeCart();
        $this->staff();
        $payload = ['channel' => 'email', 'subject' => 'Помощь с заказом', 'message' => 'Здравствуйте! Помочь с размером?'];
        $this->postJson("/api/carts/{$cart->id}/remind", $payload)->assertOk();
        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->assertDatabaseHas('cart_communications', ['cart_id' => $cart->id, 'type' => 'manual', 'status' => 'queued', 'subject' => $payload['subject'], 'body' => $payload['message']]);
        $this->postJson("/api/carts/{$cart->id}/remind", $payload)->assertUnprocessable();
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_order_links_selected_old_cart_leaving_current_cart_active_and_prevents_duplicates(): void
    {
        $cart = $this->makeCart();
        $current = Cart::create(['client_id' => $cart->client_id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['cart_id' => $cart->id, 'client_id' => $cart->client_id, 'total' => 3000];
        $service = app(OrderCreationService::class);
        $order = $service->createOrder($payload, $cart->client_id);
        $this->assertSame($cart->id, $order->cart_id);
        $this->assertSame('ordered', $cart->fresh()->status);
        $this->assertSame('active', $current->fresh()->status);
        $this->staff();
        $this->getJson("/api/carts/{$cart->id}")->assertOk()->assertJsonPath('data.orders.0.from_this_cart', true);
        $this->expectException(ValidationException::class);
        $service->createOrder($payload, $cart->client_id);
    }

    public function test_order_rejects_a_cart_for_a_different_client(): void
    {
        $cart = $this->makeCart();
        $other = $this->makeCart();
        $this->expectException(ValidationException::class);
        app(OrderCreationService::class)->createOrder(['cart_id' => $cart->id, 'client_id' => $other->client_id], $other->client_id);
    }

    public function test_preview_substitutes_current_items_without_creating_tokens_messages_or_orders(): void
    {
        $cart = $this->makeCart();
        $cart->update(['recovery_token' => null]);
        $this->staff();
        $orders = Order::count();
        $response = $this->postJson('/api/carts/preview', [
            'cart_id' => $cart->id, 'step' => 1, 'channel' => 'email', 'format' => 'html',
            'subject' => 'Для {{client.name}}',
            'body' => '<p onclick="alert(1)">{{cart.items}}</p><script>alert(2)</script><a href="javascript:alert(3)">Нет</a><a href="{{cart.url}}">Корзина</a>',
        ])->assertOk()->assertJsonPath('data.subject', 'Для Валерия');
        $html = $response->json('data.html');
        $this->assertStringContainsString('Товар для карточки', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertNull($cart->fresh()->recovery_token);
        $this->assertSame($orders, Order::count());
        $this->assertSame(0, CartCommunication::where('cart_id', $cart->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_settings_preserve_fixed_schedule_and_apply_channel_templates(): void
    {
        $cart = $this->makeCart();
        $this->staff();
        $data = ['enabled' => true, 'channels' => ['email' => true, 'telegram' => false, 'max' => false, 'vk' => false],
            'templates' => [['audience' => 'new', 'step' => 2, 'channel' => 'email', 'subject' => 'Корзина {{cart.id}}', 'body' => '{{client.name}}: {{cart.total}}', 'format' => 'text']]];
        $this->putJson('/api/carts/settings', $data)->assertOk();
        $this->getJson('/api/carts/settings')->assertOk()->assertJsonPath('data.schedule_hours', [2, 24, 48])->assertJsonPath('data.templates.0.step', 2);
        $message = app(AbandonedCartService::class)->buildMessage($cart, 2, preview: true);
        $this->assertSame('Корзина '.$cart->id, $message['subject']);
        $this->assertStringContainsString('Валерия:', $message['body']);
        $preview = $this->postJson('/api/carts/preview', ['cart_id' => $cart->id, 'step' => 2, 'channel' => 'email', 'audience' => 'new', 'use_default' => true])
            ->assertOk()->assertJsonPath('data.subject', 'Дарим 10% на первый заказ 🤍');
        $this->assertStringContainsString('{{client.name}}', $preview->json('data.editable_template.body'));
        $this->assertStringContainsString('{{cart.items}}', $preview->json('data.editable_template.body'));
        $this->assertStringNotContainsString('Валерия', $preview->json('data.editable_template.body'));
        Queue::assertNothingPushed();
    }

    public function test_automatic_switches_prevent_dispatch(): void
    {
        $cart = $this->makeCart();
        $cart->update(['status' => 'active', 'abandoned_at' => now()->subHours(3), 'recovery_cycle' => 1]);
        Setting::set('abandoned_cart_settings', ['enabled' => false, 'channels' => [], 'templates' => []]);
        $service = app(AbandonedCartService::class);
        $this->assertSame(0, $service->processChain()['sent']);
        Setting::set('abandoned_cart_settings', ['enabled' => true, 'channels' => ['email' => false, 'telegram' => false, 'max' => false, 'vk' => false], 'templates' => []]);
        $this->assertSame(0, $service->processChain()['sent']);
        Queue::assertNothingPushed();
    }

    public function test_product_summary_respects_cart_filters_and_groups_current_quantities(): void
    {
        $cart = $this->makeCart();
        $this->staff();
        $this->getJson('/api/carts/products?cart_search='.$cart->id)
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.quantity', 2)->assertJsonPath('data.data.0.total', 3000);
        $this->getJson('/api/carts/products?cart_search='.$cart->id.'&status=ordered')
            ->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_personal_message_to_ordered_cart_does_not_restart_recovery(): void
    {
        $cart = $this->makeCart();
        $cart->update(['status' => 'ordered']);
        $this->staff();
        $this->postJson("/api/carts/{$cart->id}/remind", ['channel' => 'email', 'subject' => 'Ваш заказ', 'message' => 'Здравствуйте!'])
            ->assertOk();
        $this->assertSame('ordered', $cart->fresh()->status);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_queued_automatic_message_is_cancelled_when_cart_has_been_ordered(): void
    {
        $cart = $this->makeCart();
        $cart->update(['status' => 'ordered']);
        $communication = CartCommunication::create(['cart_id' => $cart->id, 'channel' => 'email', 'type' => 'trigger', 'step' => 1, 'status' => 'queued']);
        $notifications = $this->mock(\App\Services\Notifications\NotificationService::class);
        $notifications->shouldNotReceive('sendViaChannel');
        $mirror = $this->mock(\App\Services\Notifications\NotificationConversationMirrorService::class);
        $mirror->shouldNotReceive('mirror');
        (new SendNotificationJob('email', $cart->client->email, 'Напоминание', [
            'type' => 'abandoned_cart', 'cart_id' => $cart->id, 'cart_communication_id' => $communication->id,
        ]))->handle($notifications, $mirror);
        $this->assertSame('skipped', $communication->fresh()->status);
    }
}
