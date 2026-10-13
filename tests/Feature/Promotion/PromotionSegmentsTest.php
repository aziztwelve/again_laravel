<?php

namespace Tests\Feature\Promotion;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Segments\Segment;
use App\Models\User;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionSegmentsTest extends TestCase
{
    use DatabaseTransactions;

    private function promotion(array $attributes = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Segment promotion '.uniqid(),
            'min_purchase_amount' => 0,
            'allow_promo_codes' => true,
            'is_active' => true,
            'is_stackable' => true,
            'priority' => 0,
        ], $attributes));
    }

    private function segment(bool $active = true): Segment
    {
        return Segment::create([
            'name' => 'Promotion segment '.uniqid(),
            'is_active' => $active,
            'recalculate_frequency' => 'manual',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Gift '.uniqid(), 'price' => 500,
            'is_active' => true, 'has_variants' => false, 'stock_quantity' => 10,
        ]);
    }

    private function cart(Product $product): array
    {
        return ['items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 500]], 'total' => 500];
    }

    public function test_unlinked_is_public_but_linked_requires_membership_in_an_active_segment(): void
    {
        $client = Client::factory()->create();
        $outsider = Client::factory()->create();
        $promotion = $this->promotion();
        $this->assertTrue($promotion->isAvailableForClient(null));
        $inactive = $this->segment(false);
        $active = $this->segment();
        $inactive->clients()->attach($client->id, ['added_at' => now()]);
        $promotion->segments()->attach([$inactive->id, $active->id]);
        $this->assertFalse($promotion->isAvailableForClient(null));
        $this->assertFalse($promotion->isAvailableForClient($client->id));
        $active->clients()->attach($client->id, ['added_at' => now()]);
        $this->assertTrue($promotion->isAvailableForClient($client->id));
        $this->assertFalse($promotion->isAvailableForClient($outsider->id));
        $active->clients()->detach($client->id);
        $this->assertFalse($promotion->isAvailableForClient($client->id));
        $promotion->segments()->detach();
        $this->assertTrue($promotion->isAvailableForClient(null));
    }

    public function test_segment_eligibility_does_not_bypass_cart_threshold_or_trigger_products(): void
    {
        $client = Client::factory()->create();
        $promotion = $this->promotion(['min_purchase_amount' => 1000]);
        $segment = $this->segment();
        $segment->clients()->attach($client->id, ['added_at' => now()]);
        $segment->promotions()->attach($promotion->id);
        $trigger = $this->product();
        $other = $this->product();
        $promotion->triggerProducts()->attach($trigger->id);
        $service = app(PromotionService::class);
        $items = [['product_id' => $trigger->id, 'quantity' => 1, 'price' => 1000]];
        $this->assertNotContains($promotion->id, $service->findApplicablePromotions($items, 500, $client->id)->pluck('id'));
        $this->assertNotContains($promotion->id, $service->findApplicablePromotions([['product_id' => $other->id]], 1000, $client->id)->pluck('id'));
        $this->assertContains($promotion->id, $service->findApplicablePromotions($items, 1000, $client->id)->pluck('id'));
        $this->assertNotContains($promotion->id, $service->findApplicablePromotions($items, 1000)->pluck('id'));
    }

    public function test_guest_cannot_spoof_client_identity_in_public_preview_or_list(): void
    {
        $client = Client::factory()->create();
        $promotion = $this->promotion();
        $segment = $this->segment();
        $segment->clients()->attach($client->id, ['added_at' => now()]);
        $segment->promotions()->attach($promotion->id);
        $payload = $this->cart($this->product()) + ['client_id' => $client->id];
        $response = $this->postJson('/api/public/promotions/check-applicable', $payload)->assertOk();
        $this->assertNotContains($promotion->id, collect($response->json('data'))->pluck('id'));
        $response = $this->getJson('/api/public/promotions?client_id='.$client->id)->assertOk();
        $this->assertNotContains($promotion->id, collect($response->json('data'))->pluck('id'));
    }

    public function test_customer_preview_uses_authenticated_client_and_ignores_supplied_identity(): void
    {
        $member = Client::factory()->create();
        $outsider = Client::factory()->create();
        $promotion = $this->promotion();
        $segment = $this->segment();
        $segment->clients()->attach($member->id, ['added_at' => now()]);
        $segment->promotions()->attach($promotion->id);
        $payload = $this->cart($this->product());
        Sanctum::actingAs($member);
        $response = $this->postJson('/api/public/promotions/check-applicable', $payload + ['client_id' => $outsider->id])->assertOk();
        $this->assertContains($promotion->id, collect($response->json('data'))->pluck('id'));
        Sanctum::actingAs($outsider);
        $response = $this->postJson('/api/public/promotions/check-applicable', $payload + ['client_id' => $member->id])->assertOk();
        $this->assertNotContains($promotion->id, collect($response->json('data'))->pluck('id'));
    }

    public function test_staff_can_save_segment_promotions_and_preview_for_selected_client(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $member = Client::factory()->create();
        $promotion = $this->promotion();
        $segment = $this->segment();
        $segment->clients()->attach($member->id, ['added_at' => now()]);
        $url = '/api/segments/'.$segment->id.'/promotions';
        $this->putJson($url, ['promotion_ids' => [$promotion->id]])->assertOk();
        $this->putJson($url, ['promotion_ids' => [$promotion->id]])->assertOk();
        $this->assertSame(1, $segment->promotions()->count());
        $this->getJson('/api/segments/'.$segment->id)->assertOk()
            ->assertJsonPath('data.promotions_count', 1)
            ->assertJsonPath('data.promotions.0.id', $promotion->id);
        $payload = $this->cart($this->product());
        $response = $this->postJson('/api/public/promotions/check-applicable', $payload + ['client_id' => $member->id])->assertOk();
        $this->assertContains($promotion->id, collect($response->json('data'))->pluck('id'));
        $this->putJson($url, ['promotion_ids' => []])->assertOk();
        $this->assertSame(0, $segment->promotions()->count());
    }

    public function test_segment_links_reject_deleted_promotions_and_client_mutations(): void
    {
        $promotion = $this->promotion();
        $segment = $this->segment();
        $url = '/api/segments/'.$segment->id.'/promotions';
        $this->putJson($url, ['promotion_ids' => [$promotion->id]])->assertUnauthorized();
        Sanctum::actingAs(Client::factory()->create());
        $this->putJson($url, ['promotion_ids' => [$promotion->id]])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $promotion->delete();
        $this->putJson($url, ['promotion_ids' => [$promotion->id]])->assertUnprocessable();
        $this->assertSame(0, $segment->promotions()->count());
    }

    public function test_unauthorized_gift_is_rejected_before_any_order_or_stock_mutation(): void
    {
        $member = Client::factory()->create();
        $promotion = $this->promotion();
        $segment = $this->segment();
        $segment->clients()->attach($member->id, ['added_at' => now()]);
        $segment->promotions()->attach($promotion->id);
        $gift = $this->product();
        $promotion->giftProducts()->attach($gift->id, ['quantity' => 1]);
        // Attribution of a guest order to a known email is not authentication.
        $order = Order::factory()->create(['client_id' => $member->id]);
        try {
            app(PromotionService::class)->applyPromotionsToOrder($order, [
                ['promotion_id' => $promotion->id, 'gift_product_id' => $gift->id],
            ]);
            $this->fail('Guest must not receive a segment gift');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('promotions', $error->errors());
        }
        $this->assertNull($order->fresh()->promotion_id);
        $this->assertSame(0, $order->items()->where('is_gift', true)->count());
        $this->assertSame(0, $promotion->usages()->count());
        $this->assertEquals(10, $gift->fresh()->stock_quantity);
    }

    public function test_member_receives_segment_gift_on_order(): void
    {
        $client = Client::factory()->create();
        $promotion = $this->promotion();
        $segment = $this->segment();
        $segment->clients()->attach($client->id, ['added_at' => now()]);
        $segment->promotions()->attach($promotion->id);
        $gift = $this->product();
        $promotion->giftProducts()->attach($gift->id, ['quantity' => 1]);
        $order = Order::factory()->create(['client_id' => $client->id]);
        app(PromotionService::class)->applyPromotionsToOrder($order, [
            ['promotion_id' => $promotion->id, 'gift_product_id' => $gift->id],
        ], $client->id);
        $this->assertSame(1, $order->items()->where('is_gift', true)->count());
        $this->assertEquals(9, $gift->fresh()->stock_quantity);
        $this->assertEquals(1, $promotion->fresh()->times_used);
    }
}
