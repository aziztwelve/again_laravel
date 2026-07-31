<?php

namespace Tests\Feature;

use App\Jobs\NotifyRestockSubscribersJob;
use App\Models\Category;
use App\Models\Client;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductRestockSubscription;
use App\Models\ProductVariant;
use App\Models\UserProfile;
use App\Services\Notifications\CustomerChannelResolver;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RestockSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // ConversationService резолвится на каждом запросе и требует строку
        // mail_settings — без неё падает любой HTTP-тест проекта.
        \App\Models\MailSetting::create([
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Тестовый товар '.uniqid(),
            'is_active' => true,
            'stock_quantity' => 0,
            'price' => 1000,
        ], $attrs));
    }

    public function test_guest_can_subscribe_to_restock(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/public/restock-subscriptions', [
            'product_id' => $product->id,
            'name' => 'Гость',
            'email' => 'guest@example.com',
            'phone' => '+7 (999) 123-45-67',
            'consent' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('product_restock_subscriptions', [
            'product_id' => $product->id,
            'email' => 'guest@example.com',
            'status' => 'pending',
            'source' => 'site',
        ]);

        $subscription = ProductRestockSubscription::where('product_id', $product->id)->firstOrFail();
        $this->assertNotNull($subscription->client_id);
        $this->assertNull($subscription->client->verified_at);
        $this->assertSame('+7 (999) 123-45-67', $subscription->client->profile->phone);
    }

    public function test_consent_is_required(): void
    {
        $product = $this->product();

        $this->postJson('/api/public/restock-subscriptions', [
            'product_id' => $product->id,
            'email' => 'guest@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);
    }

    public function test_subscription_rejected_when_in_stock(): void
    {
        $product = $this->product(['stock_quantity' => 5]);

        $this->postJson('/api/public/restock-subscriptions', [
            'product_id' => $product->id,
            'email' => 'guest@example.com',
            'consent' => true,
        ])->assertStatus(422);
    }

    public function test_duplicate_subscription_is_idempotent(): void
    {
        $product = $this->product();

        $payload = [
            'product_id' => $product->id,
            'email' => 'dup@example.com',
            'consent' => true,
        ];

        $this->postJson('/api/public/restock-subscriptions', $payload)->assertStatus(201);
        $this->postJson('/api/public/restock-subscriptions', $payload)->assertStatus(200);

        $this->assertEquals(1, ProductRestockSubscription::where('product_id', $product->id)
            ->where('email', 'dup@example.com')->count());
    }

    public function test_stock_transition_dispatches_job_when_pending_exists(): void
    {
        Bus::fake();

        $product = $this->product();

        ProductRestockSubscription::create([
            'product_id' => $product->id,
            'email' => 'waiting@example.com',
            'status' => 'pending',
        ]);

        // Переход остатка 0 -> >0
        $product->update(['stock_quantity' => 10]);

        Bus::assertDispatched(NotifyRestockSubscribersJob::class);
    }

    public function test_stock_transition_does_not_dispatch_without_pending(): void
    {
        Bus::fake();

        $product = $this->product();
        $product->update(['stock_quantity' => 10]);

        Bus::assertNotDispatched(NotifyRestockSubscribersJob::class);
    }

    public function test_job_notifies_and_marks_notified(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $product = $this->product(['stock_quantity' => 10]);

        $subscription = ProductRestockSubscription::create([
            'product_id' => $product->id,
            'email' => 'notify@example.com',
            'status' => 'pending',
        ]);

        (new NotifyRestockSubscribersJob($product->id))->handle(app(CustomerChannelResolver::class));

        // Email — единственный гостевой канал.
        Bus::assertDispatched(SendNotificationJob::class);

        $this->assertEquals('notified', $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->notified_at);
    }

    public function test_job_is_idempotent_for_notified(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $product = $this->product(['stock_quantity' => 10]);

        ProductRestockSubscription::create([
            'product_id' => $product->id,
            'email' => 'already@example.com',
            'status' => 'notified',
            'notified_at' => now(),
        ]);

        (new NotifyRestockSubscribersJob($product->id))->handle(app(CustomerChannelResolver::class));

        // Уже notified — повторно не шлём.
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_job_waits_for_the_selected_color(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $product = $this->product(['stock_quantity' => 1]);
        $availableColor = Color::create(['name' => 'Красный '.uniqid(), 'code' => '#ff0000']);
        $waitingColor = Color::create(['name' => 'Чёрный '.uniqid(), 'code' => '#000000']);

        ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $availableColor->id,
            'name' => 'Красный',
            'sku' => 'restock-red-'.uniqid(),
            'price' => 1000,
            'stock_quantity' => 1,
        ]);
        $waitingVariant = ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $waitingColor->id,
            'name' => 'Чёрный',
            'sku' => 'restock-black-'.uniqid(),
            'price' => 1000,
            'stock_quantity' => 0,
        ]);

        $subscription = ProductRestockSubscription::create([
            'product_id' => $product->id,
            'email' => 'color@example.com',
            'color_ids' => [$waitingColor->id],
            'status' => 'pending',
        ]);

        (new NotifyRestockSubscribersJob($product->id))->handle(app(CustomerChannelResolver::class));

        Bus::assertNotDispatched(SendNotificationJob::class);
        $this->assertSame('pending', $subscription->fresh()->status);

        $waitingVariant->update(['stock_quantity' => 1]);
        (new NotifyRestockSubscribersJob($product->id))->handle(app(CustomerChannelResolver::class));

        Bus::assertDispatched(SendNotificationJob::class);
        $this->assertSame('notified', $subscription->fresh()->status);
    }

    public function test_job_notifies_linked_subscriber_in_all_transactional_channels(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $product = $this->product(['stock_quantity' => 10]);
        $client = Client::create(['email' => 'all-channels@example.com']);
        UserProfile::create([
            'client_id' => $client->id,
            'telegram_user_id' => 123456,
            'max_user_id' => 234567,
            'vk_user_id' => 345678,
        ]);

        ProductRestockSubscription::create([
            'product_id' => $product->id,
            'client_id' => $client->id,
            'email' => $client->email,
            'status' => 'pending',
        ]);

        (new NotifyRestockSubscribersJob($product->id))->handle(app(CustomerChannelResolver::class));

        Bus::assertDispatched(SendNotificationJob::class, 4);
    }

    public function test_public_catalog_hides_out_of_stock_products_by_default(): void
    {
        $inStock = $this->product(['stock_quantity' => 5]);
        $outOfStock = $this->product(['stock_quantity' => 0]);

        $response = $this->getJson('/api/public/catalog/products?per_page=50');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inStock->id));
        $this->assertFalse($ids->contains($outOfStock->id));
    }

    public function test_coming_soon_category_shows_all_manually_selected_products(): void
    {
        $category = Category::create([
            'name' => 'Скоро в продаже '.uniqid(),
            'is_coming_soon' => true,
            'show_in_catalog_menu' => true,
        ]);

        $inStock = $this->product(['stock_quantity' => 5]);
        $outOfStock = $this->product(['stock_quantity' => 0]);
        $category->products()->attach([$inStock->id, $outOfStock->id]);

        $response = $this->getJson('/api/public/catalog/products?per_page=50&category_slug='.$category->slug);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inStock->id));
        $this->assertTrue($ids->contains($outOfStock->id));
    }
}
