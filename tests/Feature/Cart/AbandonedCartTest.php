<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartCommunication;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserProfile;
use App\Services\Cart\AbandonedCartService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AbandonedCartTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Заглушка mail_settings — без неё падает резолв ConversationService.
        \App\Models\MailSetting::create([
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);

        // Окно отправки — круглосуточно, чтобы тест не зависел от времени суток.
        config([
            'abandoned_cart.send_window.start_hour' => 0,
            'abandoned_cart.send_window.end_hour' => 24,
        ]);
    }

    private function service(): AbandonedCartService
    {
        return app(AbandonedCartService::class);
    }

    private function client(array $profile = ['email_only' => true]): Client
    {
        static $i = 0;
        $i++;

        $client = Client::create([
            'email' => $profile['email'] ?? "cart{$i}@example.com",
            'password' => bcrypt('secret'),
        ]);

        UserProfile::create([
            'client_id' => $client->id,
            'first_name' => 'Тест',
            'last_name' => 'Клиент',
            'phone' => $profile['phone'] ?? null,
            'telegram_chat_id' => $profile['telegram_chat_id'] ?? null,
            'max_user_id' => $profile['max_user_id'] ?? null,
            'vk_user_id' => $profile['vk_user_id'] ?? null,
        ]);

        return $client->fresh('profile');
    }

    private function product(): Product
    {
        static $i = 0;
        $i++;

        return Product::create([
            'name' => "Товар {$i}",
            'slug' => "tovar-{$i}-".uniqid(),
            'price' => 1990,
            'currency' => 'RUB',
            'is_active' => true,
            'has_variants' => false,
            'stock_quantity' => 10,
        ]);
    }

    private function cart(Client $client, ?string $status, \DateTimeInterface $activity, array $extra = []): Cart
    {
        $cart = Cart::create(array_merge([
            'client_id' => $client->id,
            'status' => $status,
            'created_at' => $activity,
            'updated_at' => $activity,
            'total' => 1990,
            'total_original' => 1990,
            'total_discount' => 0,
        ], $extra));

        $cart->items()->create([
            'product_id' => $this->product()->id,
            'quantity' => 1,
            'price' => 1990,
            'price_original' => 1990,
            'total' => 1990,
            'total_original' => 1990,
            'total_discount' => 0,
        ]);

        return $cart->fresh('items');
    }

    public function test_marks_only_inactive_carts_as_abandoned(): void
    {
        $stale = $this->cart($this->client(), 'active', now()->subHours(25));
        $fresh = $this->cart($this->client(), 'active', now()->subHour());

        $marked = $this->service()->markAbandonedCarts();

        $this->assertSame(1, $marked);
        $stale->refresh();
        $this->assertSame('abandoned', $stale->status);
        $this->assertNotNull($stale->abandoned_at);
        $this->assertNotNull($stale->recovery_token);

        $fresh->refresh();
        $this->assertSame('active', $fresh->status);
    }

    public function test_sends_first_step_and_is_idempotent(): void
    {
        Queue::fake();

        $client = $this->client(['email' => 'buyer@example.com']);
        $cart = $this->cart($client, 'abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $result = $this->service()->processChain();

        $this->assertSame(1, $result['sent']);
        Queue::assertPushed(SendNotificationJob::class, 1);

        $this->assertDatabaseHas('cart_communications', [
            'cart_id' => $cart->id,
            'step' => 1,
            'channel' => 'email',
            'status' => 'queued',
        ]);

        // Повторный прогон не должен слать шаг 1 ещё раз.
        $result2 = $this->service()->processChain();
        $this->assertSame(0, $result2['sent']);
        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->assertSame(1, CartCommunication::where('cart_id', $cart->id)->count());
    }

    public function test_new_client_receives_first_order_copy(): void
    {
        $client = $this->client(['email' => 'new@example.com']);
        $cart = $this->cart($client, 'abandoned', now(), ['recovery_token' => 'new-'.uniqid()]);

        $message = $this->service()->buildMessage($cart->fresh('client.profile', 'items.product'), 2);

        $this->assertSame('Дарим 10% на первый заказ 🤍', $message['subject']);
        $this->assertStringContainsString('FIRST10', $message['body']);
    }

    public function test_returning_client_receives_returning_copy(): void
    {
        $client = $this->client(['email' => 'returning@example.com']);
        Order::create([
            'order_number' => 'TEST-'.uniqid(),
            'client_id' => $client->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_amount' => 1990,
        ]);
        $cart = $this->cart($client, 'abandoned', now(), ['recovery_token' => 'returning-'.uniqid()]);

        $message = $this->service()->buildMessage($cart->fresh('client.profile', 'items.product'), 1);

        $this->assertSame('Тест, вы снова выбрали нас ❤️', $message['subject']);
        $this->assertStringContainsString('приоритетная обработка', $message['body']);
        $this->assertStringNotContainsString('FIRST10', $message['body']);
    }

    public function test_sends_abandoned_cart_to_every_available_channel(): void
    {
        Queue::fake();

        $client = $this->client([
            'email' => 'all@example.com',
            'telegram_chat_id' => '123456',
            'max_user_id' => '234567',
            'vk_user_id' => '345678',
        ]);
        $cart = $this->cart($client, 'abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $result = $this->service()->processChain();

        $this->assertSame(4, $result['sent']);
        Queue::assertPushed(SendNotificationJob::class, 4);
        $this->assertSame(4, CartCommunication::where('cart_id', $cart->id)->where('step', 1)->count());
    }

    public function test_resolves_all_available_transactional_channels(): void
    {
        $client = $this->client([
            'email' => 'x@example.com',
            'telegram_chat_id' => '123456',
            'max_user_id' => '234567',
            'vk_user_id' => '345678',
        ]);

        $cart = $this->cart($client, 'abandoned', now());

        $recipients = $this->service()->resolveChannels($cart);

        $this->assertSame(['email', 'telegram', 'max', 'vk'], array_column($recipients, 'channel'));
        $this->assertSame(['x@example.com', '123456', '234567', '345678'], array_column($recipients, 'recipient_id'));
    }

    public function test_step_is_skipped_when_no_transactional_contact(): void
    {
        Queue::fake();

        // Клиент без контактов: email null, профиль пустой.
        $client = Client::create(['email' => null, 'password' => bcrypt('x')]);
        UserProfile::create(['client_id' => $client->id, 'first_name' => 'N']);

        $cart = $this->cart($client->fresh('profile'), 'abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $result = $this->service()->processChain();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('cart_communications', [
            'cart_id' => $cart->id,
            'step' => 1,
        ]);
    }

    public function test_restore_endpoint_returns_items(): void
    {
        $cart = $this->cart($this->client(), 'abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'restore-token-123',
        ]);

        $response = $this->getJson('/api/public/cart/restore/restore-token-123');

        $response->assertOk()
            ->assertJson(['success' => true, 'cart_id' => $cart->id])
            ->assertJsonStructure(['items' => [['product_id', 'qty', 'name', 'price']]]);
    }

    public function test_restore_endpoint_404_for_unknown_token(): void
    {
        $this->getJson('/api/public/cart/restore/nope')->assertStatus(404);
    }

    // ===================== Гости не участвуют в abandoned-cart =====================

    /**
     * Гостевая корзина (без client_id) с непустым составом.
     */
    private function guestCart(?string $status, \DateTimeInterface $activity, array $extra = []): Cart
    {
        $cart = Cart::create(array_merge([
            'guest_token' => 'guest-'.uniqid(),
            'status' => $status,
            'created_at' => $activity,
            'updated_at' => $activity,
            'last_activity_at' => $activity,
            'total' => 1990,
            'total_original' => 1990,
            'total_discount' => 0,
        ], $extra));

        $cart->items()->create([
            'product_id' => $this->product()->id,
            'quantity' => 1,
            'price' => 1990,
            'price_original' => 1990,
            'total' => 1990,
            'total_original' => 1990,
            'total_discount' => 0,
        ]);

        return $cart->fresh('items');
    }

    public function test_does_not_mark_guest_cart_as_abandoned(): void
    {
        $cart = $this->guestCart('active', now()->subHours(25));

        $marked = $this->service()->markAbandonedCarts();

        $this->assertSame(0, $marked);
        $cart->refresh();
        $this->assertSame('active', $cart->status);
        $this->assertNull($cart->abandoned_at);
        $this->assertNull($cart->recovery_token);
    }

    public function test_guest_with_consent_does_not_resolve_channel(): void
    {
        $cart = $this->guestCart('abandoned', now(), [
            'email' => 'guest@example.com',
            'marketing_consent' => true,
            'consent_at' => now(),
        ]);

        [$channel, $recipient] = $this->service()->resolveChannel($cart);

        $this->assertNull($channel);
        $this->assertNull($recipient);
    }

    public function test_guest_with_consent_is_not_in_chain(): void
    {
        Queue::fake();

        $cart = $this->guestCart('abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'g-tok-'.uniqid(),
            'email' => 'guest@example.com',
            'marketing_consent' => true,
            'consent_at' => now(),
        ]);

        $result = $this->service()->processChain();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['skipped']);
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('cart_communications', ['cart_id' => $cart->id]);
    }

    public function test_guest_recovery_token_is_not_accepted(): void
    {
        $cart = $this->guestCart('abandoned', now()->subHour(), [
            'abandoned_at' => now()->subHour(),
            'recovery_token' => 'recover-me-1',
        ]);

        $this->getJson('/api/public/cart/recovery/recover-me-1')
            ->assertStatus(404);

        $cart->refresh();
        $this->assertSame('abandoned', $cart->status);
    }

    public function test_update_contact_endpoint_saves_consent(): void
    {
        $response = $this->patchJson('/api/cart/contact', [
            'email' => 'guest@example.com',
            'phone' => '+79990001122',
            'consent' => true,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('cart', [
            'email' => 'guest@example.com',
            'phone' => '+79990001122',
            'marketing_consent' => 1,
        ]);
    }

    public function test_guest_order_links_cart_by_guest_token(): void
    {
        $cart = $this->guestCart('active', now(), ['guest_token' => 'order-guest-tok']);

        $order = app(\App\Services\Order\OrderCreationService::class)->createOrder([
            'total' => 1990,
            'guest_token' => 'order-guest-tok',
        ], null);

        $cart->refresh();
        $this->assertSame('ordered', $cart->status);
        $this->assertNotNull($cart->ordered_at);
        $this->assertSame($cart->id, $order->cart_id);
    }

    // ===================== Ручная отправка (F) + версии (G) — Фаза 4 =====================

    public function test_send_manual_reminder_sends_and_logs(): void
    {
        Queue::fake();

        $client = $this->client(['email' => 'manual@example.com']);
        $cart = $this->cart($client, 'abandoned', now());

        $result = $this->service()->sendManual($cart);

        $this->assertTrue($result['ok']);
        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->assertDatabaseHas('cart_communications', [
            'cart_id' => $cart->id,
            'type' => 'manual',
            'status' => 'queued',
            'step' => null,
        ]);
    }

    public function test_send_manual_generates_recovery_token_and_link(): void
    {
        Queue::fake();

        $client = $this->client(['email' => 'link@example.com']);
        // active-корзина без recovery_token — как при ручной отправке из админки.
        $cart = $this->cart($client, 'active', now());
        $this->assertNull($cart->recovery_token);

        $result = $this->service()->sendManual($cart);
        $this->assertTrue($result['ok']);

        // Токен выдан лениво и сохранён.
        $cart->refresh();
        $this->assertNotNull($cart->recovery_token);

        // Ссылка в письме содержит токен и не оканчивается на «/recovery/» (404).
        $base = rtrim((string) config('abandoned_cart.recovery_url'), '/');
        $body = $this->service()->buildMessage($cart, 1)['body'];
        $this->assertStringContainsString($base.'/'.$cart->recovery_token, $body);
        $this->assertStringNotContainsString($base."/\n", $body);
        $this->assertStringNotContainsString($base.'/ ', $body);
    }

    public function test_send_manual_is_throttled(): void
    {
        Queue::fake();

        $client = $this->client(['email' => 'm2@example.com']);
        $cart = $this->cart($client, 'abandoned', now());

        $first = $this->service()->sendManual($cart);
        $this->assertTrue($first['ok']);

        $second = $this->service()->sendManual($cart);
        $this->assertFalse($second['ok']);
        $this->assertSame('throttled', $second['reason']);

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_send_manual_blocked_for_guest(): void
    {
        $cart = $this->guestCart('abandoned', now(), [
            'email' => 'guest@example.com',
            'marketing_consent' => true,
        ]);

        $result = $this->service()->sendManual($cart);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_eligible', $result['reason']);
    }

    public function test_remind_endpoint_sends(): void
    {
        Queue::fake();

        $client = $this->client(['email' => 'ep@example.com']);
        $cart = $this->cart($client, 'abandoned', now());

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/carts/{$cart->id}/remind")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('cart_communications', [
            'cart_id' => $cart->id,
            'type' => 'manual',
        ]);
    }

    public function test_versions_count_in_carts_list(): void
    {
        $client = $this->client();
        // Две корзины одного клиента → versions_count = 2.
        $this->cart($client, 'abandoned', now()->subDay(), ['recovery_token' => 'v1-'.uniqid()]);
        $this->cart($client, 'ordered', now(), ['ordered_at' => now()]);

        $response = $this->actingAs($client, 'sanctum')->getJson('/api/carts?per_page=50');
        $response->assertOk();

        $row = collect($response->json('data.data'))
            ->firstWhere(fn ($r) => ($r['customer']['email'] ?? null) === $client->email);

        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(2, $row['versions_count']);
    }

    // ===================== Промокод на шаге 2 (фаза 2) =====================

    private function enablePromo(): void
    {
        config([
            'abandoned_cart.promo.enabled' => true,
            'abandoned_cart.promo.step' => 2,
            'abandoned_cart.promo.discount_type' => 'percentage',
            'abandoned_cart.promo.discount_amount' => 10,
            'abandoned_cart.promo.ttl_days' => 7,
            'abandoned_cart.promo.code_prefix' => 'CART',
        ]);
    }

    public function test_step2_issues_promo_code_when_enabled(): void
    {
        Queue::fake();
        $this->enablePromo();

        $client = $this->client(['email' => 'promo@example.com']);
        // abandoned_at 73ч назад → due и шаг 1 (0ч), и шаг 2 (48ч).
        $cart = $this->cart($client, 'abandoned', now()->subHours(73), [
            'abandoned_at' => now()->subHours(73),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $this->service()->processChain();

        $cart->refresh();
        $this->assertNotNull($cart->recovery_promo_code);
        $this->assertDatabaseHas('promo_codes', [
            'code' => $cart->recovery_promo_code,
            'max_uses' => 1,
            'is_active' => 1,
            'applies_to_all_clients' => 1,
        ]);
    }

    public function test_promo_code_not_regenerated_on_repeat(): void
    {
        Queue::fake();
        $this->enablePromo();

        $client = $this->client(['email' => 'promo2@example.com']);
        $cart = $this->cart($client, 'abandoned', now()->subHours(73), [
            'abandoned_at' => now()->subHours(73),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $this->service()->processChain();
        $cart->refresh();
        $code = $cart->recovery_promo_code;
        $this->assertNotNull($code);
        $this->assertSame(1, \App\Models\PromoCode::where('code', $code)->count());

        // Повторный прогон не плодит новые промокоды (шаг 2 уже отправлен).
        $this->service()->processChain();
        $this->assertSame(1, \App\Models\PromoCode::where('code', $code)->count());
    }

    public function test_no_promo_code_when_disabled(): void
    {
        Queue::fake();
        config(['abandoned_cart.promo.enabled' => false]);

        $client = $this->client(['email' => 'promo3@example.com']);
        $cart = $this->cart($client, 'abandoned', now()->subHours(73), [
            'abandoned_at' => now()->subHours(73),
            'recovery_token' => 'tok'.uniqid(),
        ]);

        $this->service()->processChain();

        $cart->refresh();
        $this->assertNull($cart->recovery_promo_code);
    }
}
