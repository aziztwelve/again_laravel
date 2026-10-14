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

    public function test_client_cannot_read_another_clients_cart_or_draft(): void
    {
        $cart = $this->makeCart();
        $otherClient = Client::create(['email' => 'other-'.uniqid().'@example.com', 'password' => bcrypt('secret')]);
        $this->actingAs($otherClient, 'sanctum');
        $this->getJson("/api/carts/{$cart->id}")->assertForbidden();
        $this->getJson("/api/carts/{$cart->id}/order-draft")->assertForbidden();
        $this->postJson("/api/carts/{$cart->id}/remind", ['channel' => 'email', 'message' => 'Привет'])->assertForbidden();
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
}
