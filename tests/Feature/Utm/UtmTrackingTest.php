<?php

namespace Tests\Feature\Utm;

use App\Models\MarketingChannel;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Models\UtmLink;
use App\Models\UtmVisit;
use App\Services\Order\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UtmTrackingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Приложение резолвит ConversationService на каждом запросе, а его
        // EmailAdapter требует строку mail_settings. В свежей тестовой БД её нет,
        // поэтому создаём заглушку (без неё падает любой HTTP-тест проекта).
        \App\Models\MailSetting::create([
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);
    }

    private function channel(array $attrs = []): MarketingChannel
    {
        return MarketingChannel::create(array_merge([
            'name' => 'Instagram',
            'code' => 'ig',
            'is_system' => false,
            'is_active' => true,
            'sort' => 0,
        ], $attrs));
    }

    private function link(MarketingChannel $channel, array $attrs = []): UtmLink
    {
        return UtmLink::create(array_merge([
            'name' => 'Блогер1',
            'marketing_channel_id' => $channel->id,
            'target_url' => 'https://example.com/page',
            'utm_source' => $channel->code,
            'slug' => 'abc12345',
            'is_active' => true,
        ], $attrs));
    }

    private function makeOrder(int $linkId, string $paymentStatus, float $total, ?int $clientId): void
    {
        static $counter = 0;
        $counter++;

        Order::create([
            'order_number' => 'TEST-'.$counter,
            'client_id' => $clientId,
            'status' => 'new',
            'payment_status' => $paymentStatus,
            'total_amount' => $total,
            'utm_link_id' => $linkId,
            'created_at' => now(),
        ]);
    }

    // === Редирект-трекер /go/{slug} ===

    public function test_redirect_tracker_records_visit_sets_cookie_and_redirects(): void
    {
        $channel = $this->channel();
        $link = $this->link($channel, ['slug' => 'go123abc']);

        $response = $this->get('/go/go123abc');

        $response->assertRedirect('https://example.com/page?utm_source=ig');
        $response->assertPlainCookie('utm_link_id', (string) $link->id);

        $this->assertDatabaseCount('utm_visits', 1);
        $this->assertDatabaseHas('utm_visits', ['utm_link_id' => $link->id]);
    }

    public function test_redirect_tracker_returns_404_for_inactive_or_unknown_slug(): void
    {
        $channel = $this->channel();
        $this->link($channel, ['slug' => 'inactive1', 'is_active' => false]);

        $this->get('/go/inactive1')->assertNotFound();
        $this->get('/go/missing999')->assertNotFound();

        $this->assertDatabaseCount('utm_visits', 0);
    }

    // === Атрибуция заказа к метке (utm_link_id из orderData → заказ) ===

    public function test_order_creation_persists_utm_link_id(): void
    {
        $channel = $this->channel();
        $link = $this->link($channel);

        /** @var OrderCreationService $service */
        $service = app(OrderCreationService::class);

        $order = $service->createOrder([
            'status' => 'new',
            'payment_status' => 'pending',
            'total' => 1500,
            'utm_link_id' => $link->id,
            'items' => [],
        ], null);

        $this->assertSame($link->id, $order->utm_link_id);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'utm_link_id' => $link->id,
        ]);
    }

    // === Аналитика: метрики, уникальные посещения, исключение возвратов ===

    public function test_analytics_aggregates_metrics_correctly(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel();
        $link = $this->link($channel);

        // 3 посещения, но 2 уникальных visitor_hash.
        foreach (['hashA', 'hashA', 'hashB'] as $hash) {
            UtmVisit::create([
                'utm_link_id' => $link->id,
                'visited_at' => now(),
                'visitor_hash' => $hash,
            ]);
        }

        // paid 1000 (client1), pending 500 (client2), refunded 300 (client1)
        $client1 = Client::factory()->create();
        $client2 = Client::factory()->create();
        $this->makeOrder($link->id, 'paid', 1000, $client1->id);
        $this->makeOrder($link->id, 'pending', 500, $client2->id);
        $this->makeOrder($link->id, 'refunded', 300, $client1->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/analytics/utm?preset=all');

        $response->assertOk();

        $row = collect($response->json('rows'))->firstWhere('link_id', $link->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['visits']);             // уникальные посещения
        $this->assertSame(3, $row['orders']);             // все заказы
        $this->assertEquals(1800, $row['orders_amount']); // оборот (вкл. возврат)
        $this->assertSame(1, $row['purchases']);          // только paid
        $this->assertEquals(1000, $row['purchases_amount']); // сумма paid
        $this->assertSame(2, $row['clients']);            // distinct client_id
        $this->assertEquals(150, $row['cr_order']);       // 3/2*100
        $this->assertEquals(33.3, $row['cr_purchase']);   // 1/3*100
    }

    public function test_analytics_pie_uses_distinct_clients(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel();
        $link = $this->link($channel);

        $this->makeOrder($link->id, 'paid', 1000, Client::factory()->create()->id);
        $this->makeOrder($link->id, 'paid', 1000, Client::factory()->create()->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/analytics/utm?preset=all');

        $response->assertOk();
        $pie = $response->json('pie');

        $this->assertContains(2, $pie['data']);
    }

    // === CRUD ===

    public function test_cannot_delete_system_channel(): void
    {
        $user = User::factory()->create();
        $systemChannel = $this->channel(['code' => 'sys', 'is_system' => true]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/utm/marketing-channels/{$systemChannel->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('marketing_channels', ['id' => $systemChannel->id]);
    }

    public function test_can_create_link_with_generated_slug_and_tracking_url(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/utm/links', [
            'name' => 'Тест метка',
            'marketing_channel_id' => $channel->id,
            'target_url' => 'https://example.com/landing',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertNotEmpty($data['slug']);
        $this->assertSame('ig', $data['utm_source']); // из кода канала
        $this->assertStringContainsString('/go/'.$data['slug'], $data['tracking_url']);
    }

    public function test_can_soft_delete_link(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel();
        $link = $this->link($channel);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/utm/links/{$link->id}")
            ->assertOk();

        $this->assertSoftDeleted('utm_links', ['id' => $link->id]);
    }

    public function test_target_url_host_is_restricted_when_allowlist_configured(): void
    {
        config(['utm.allowed_target_hosts' => ['example.com']]);

        $user = User::factory()->create();
        $channel = $this->channel();

        // Сторонний домен → отклоняется.
        $this->actingAs($user, 'sanctum')->postJson('/api/utm/links', [
            'name' => 'Левая ссылка',
            'marketing_channel_id' => $channel->id,
            'target_url' => 'https://evil.com/phishing',
        ])->assertStatus(422)->assertJsonValidationErrors('target_url');

        // Разрешённый домен → проходит.
        $this->actingAs($user, 'sanctum')->postJson('/api/utm/links', [
            'name' => 'Своя ссылка',
            'marketing_channel_id' => $channel->id,
            'target_url' => 'https://example.com/landing',
        ])->assertStatus(201);
    }
}
