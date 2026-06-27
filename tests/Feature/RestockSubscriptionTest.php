<?php

namespace Tests\Feature;

use App\Jobs\NotifyRestockSubscribersJob;
use App\Models\Product;
use App\Models\ProductRestockSubscription;
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
            'name' => 'Тестовый товар ' . uniqid(),
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
            'email' => 'guest@example.com',
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
            'phone' => '+79991234567',
            'status' => 'pending',
        ]);

        (new NotifyRestockSubscribersJob($product->id))->handle();

        // email + whatsapp
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

        (new NotifyRestockSubscribersJob($product->id))->handle();

        // Уже notified — повторно не шлём.
        Bus::assertNotDispatched(SendNotificationJob::class);
    }
}
