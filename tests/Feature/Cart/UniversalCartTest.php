<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Client;
use App\Models\Product;
use App\Services\Cart\CartMerger;
use App\Services\Cart\CartResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class UniversalCartTest extends TestCase
{
    use DatabaseTransactions;

    private function resolver(): CartResolver
    {
        return app(CartResolver::class);
    }

    private function merger(): CartMerger
    {
        return app(CartMerger::class);
    }

    private function client(): Client
    {
        static $i = 0;
        $i++;

        return Client::create([
            'email' => "uni{$i}@example.com",
            'password' => bcrypt('secret'),
        ]);
    }

    private function product(float $price = 1000): Product
    {
        static $i = 0;
        $i++;

        return Product::create([
            'name' => "Товар {$i}",
            'slug' => "uni-tovar-{$i}-".uniqid(),
            'price' => $price,
            'currency' => 'RUB',
            'is_active' => true,
            'has_variants' => false,
            'stock_quantity' => 100,
        ]);
    }

    private function addItem(Cart $cart, int $productId, int $qty, float $price): void
    {
        $cart->items()->create([
            'product_id' => $productId,
            'quantity' => $qty,
            'price' => $price,
            'price_original' => $price,
            'total' => $price * $qty,
            'total_original' => $price * $qty,
            'total_discount' => 0,
        ]);
    }

    private function cookieName(): string
    {
        return config('cart.cookie.name', 'guest_token');
    }

    public function test_guest_without_cookie_gets_new_cart_and_cookie(): void
    {
        $request = Request::create('/api/cart/items', 'POST');

        $cart = $this->resolver()->resolveOrCreate($request);

        $this->assertNotNull($cart->guest_token);
        $this->assertNull($cart->client_id);
        $this->assertSame('active', $cart->status);
        $this->assertNotNull($cart->last_activity_at);

        // Cookie guest_token поставлена в очередь ответа.
        $queued = collect(Cookie::getQueuedCookies())
            ->firstWhere(fn ($c) => $c->getName() === $this->cookieName());

        $this->assertNotNull($queued);
        $this->assertSame($cart->guest_token, $queued->getValue());
        $this->assertTrue($queued->isHttpOnly());
    }

    public function test_guest_with_cookie_resolves_same_cart(): void
    {
        $existing = Cart::create([
            'guest_token' => 'guest-tok-123',
            'status' => 'active',
            'created_at' => now(),
        ]);

        $request = Request::create('/api/cart/items', 'POST', [], [$this->cookieName() => 'guest-tok-123']);

        $cart = $this->resolver()->resolveOrCreate($request);

        $this->assertSame($existing->id, $cart->id);
    }

    public function test_authenticated_client_resolves_client_cart(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'sanctum');

        $cart = $this->resolver()->resolveOrCreate(Request::create('/api/cart/items', 'POST'));

        $this->assertSame($client->id, $cart->client_id);
        $this->assertNull($cart->guest_token);
        $this->assertSame('active', $cart->status);
    }

    public function test_resolve_active_returns_null_for_guest_without_cart(): void
    {
        $request = Request::create('/api/cart', 'GET');

        $this->assertNull($this->resolver()->resolveActive($request));
    }

    public function test_merger_migrates_guest_cart_when_client_has_none(): void
    {
        $client = $this->client();
        $product = $this->product();

        $guest = Cart::create([
            'guest_token' => 'mig-tok',
            'status' => 'active',
            'created_at' => now(),
        ]);
        $this->addItem($guest, $product->id, 2, 1000);

        $result = $this->merger()->attachGuestCartToClient($client->id, 'mig-tok');

        $this->assertNotNull($result);
        $this->assertSame($guest->id, $result->id);
        $this->assertSame($client->id, $result->client_id);
        $this->assertNull($result->guest_token);
        $this->assertCount(1, $result->items);
    }

    public function test_merger_merges_into_existing_client_cart(): void
    {
        $client = $this->client();
        $productA = $this->product();
        $productB = $this->product();

        // Клиентская корзина: A x1.
        $clientCart = Cart::create([
            'client_id' => $client->id,
            'status' => 'active',
            'created_at' => now(),
        ]);
        $this->addItem($clientCart, $productA->id, 1, 1000);

        // Гостевая: A x2 (дубль) + B x1.
        $guest = Cart::create([
            'guest_token' => 'merge-tok',
            'status' => 'active',
            'created_at' => now(),
        ]);
        $this->addItem($guest, $productA->id, 2, 1000);
        $this->addItem($guest, $productB->id, 1, 500);

        $result = $this->merger()->attachGuestCartToClient($client->id, 'merge-tok');

        $this->assertSame($clientCart->id, $result->id);

        // A суммируется до 3, B добавлен.
        $itemA = $result->items->firstWhere('product_id', $productA->id);
        $itemB = $result->items->firstWhere('product_id', $productB->id);
        $this->assertSame(3, (int) $itemA->quantity);
        $this->assertNotNull($itemB);
        $this->assertCount(2, $result->items);

        // Гостевая корзина удалена.
        $this->assertNull(Cart::where('guest_token', 'merge-tok')->first());

        // Итоги пересчитаны: 3*1000 + 1*500 = 3500.
        $this->assertEquals(3500, (float) $result->fresh()->total);
    }

    public function test_guest_endpoint_creates_server_cart(): void
    {
        $product = $this->product(1990);

        $response = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 1990,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertCookie($this->cookieName());

        $this->assertDatabaseHas('cart', [
            'client_id' => null,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    // ===================== GC гостевых корзин + сегменты аналитики (Фаза 5) =====================

    private function guestCartRow(string $status, \DateTimeInterface $activity, bool $withItem): Cart
    {
        $cart = Cart::create([
            'guest_token' => 'gc-'.uniqid(),
            'status' => $status,
            'created_at' => $activity,
            'updated_at' => $activity,
            'last_activity_at' => $activity,
            'total' => 0,
            'total_original' => 0,
            'total_discount' => 0,
        ]);

        if ($withItem) {
            $product = $this->product();
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 1000,
                'price_original' => 1000,
                'total' => 1000,
                'total_original' => 1000,
                'total_discount' => 0,
            ]);
        }

        return $cart;
    }

    public function test_gc_deletes_empty_guest_cart(): void
    {
        $cart = $this->guestCartRow('active', now()->subHours(72), false);

        $this->artisan('cart:gc-guest-carts')->assertExitCode(0);

        $this->assertNull(Cart::find($cart->id));
    }

    public function test_gc_keeps_recent_empty_guest_cart(): void
    {
        $cart = $this->guestCartRow('active', now()->subHour(), false);

        $this->artisan('cart:gc-guest-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::find($cart->id));
    }

    public function test_gc_deletes_stale_guest_cart_with_items(): void
    {
        $cart = $this->guestCartRow('abandoned', now()->subDays(120), true);

        $this->artisan('cart:gc-guest-carts')->assertExitCode(0);

        $this->assertNull(Cart::find($cart->id));
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
    }

    public function test_gc_keeps_client_and_ordered_carts(): void
    {
        $client = $this->client();

        // Пустая клиентская корзина (старая) — не трогаем (есть client_id).
        $clientCart = Cart::create([
            'client_id' => $client->id,
            'status' => 'active',
            'created_at' => now()->subDays(200),
            'updated_at' => now()->subDays(200),
            'last_activity_at' => now()->subDays(200),
            'total' => 0,
        ]);

        // Оформленная гостевая (старая) — не трогаем (ordered).
        $orderedGuest = $this->guestCartRow('ordered', now()->subDays(200), true);

        $this->artisan('cart:gc-guest-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::find($clientCart->id));
        $this->assertNotNull(Cart::find($orderedGuest->id));
    }

    public function test_analytics_returns_guest_registered_segments(): void
    {
        $client = $this->client();

        // registered: 1 брошенная.
        Cart::create([
            'client_id' => $client->id,
            'status' => 'abandoned',
            'created_at' => now(),
            'total' => 1000,
            'total_original' => 1000,
            'total_discount' => 0,
        ]);

        // guest: 1 брошенная + 1 оформленная.
        $this->guestCartRow('abandoned', now(), false);
        Cart::create([
            'guest_token' => 'seg-ord-'.uniqid(),
            'status' => 'ordered',
            'created_at' => now(),
            'total' => 700,
            'total_original' => 700,
            'total_discount' => 0,
        ]);

        $response = $this->actingAs($client, 'sanctum')->getJson('/api/carts/analytics');
        $response->assertOk();

        $segments = $response->json('data.segments');

        $this->assertSame(1, $segments['guest']['abandoned']);
        $this->assertSame(1, $segments['guest']['ordered']);
        $this->assertEquals(50.0, $segments['guest']['rate']);
        $this->assertSame(1, $segments['registered']['abandoned']);
        $this->assertSame(0, $segments['registered']['ordered']);
    }
}
