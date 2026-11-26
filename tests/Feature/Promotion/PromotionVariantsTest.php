<?php

namespace Tests\Feature\Promotion;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionVariantsTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Product, 1: ProductVariant, 2: ProductVariant} */
    private function gel(): array
    {
        $product = Product::create([
            'name' => 'Гель '.uniqid(), 'price' => 1990, 'is_active' => true,
            'has_variants' => true, 'stock_quantity' => 0,
        ]);
        $variant = fn (string $name) => ProductVariant::create([
            'product_id' => $product->id, 'name' => $name, 'sku' => 'gel-'.uniqid(),
            'price' => 1990, 'stock_quantity' => 100, 'is_active' => true,
        ]);

        return [$product, $variant('100 мл'), $variant('300 мл')];
    }

    private function promotion(array $attributes = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Variant promotion '.uniqid(), 'min_purchase_amount' => 0, 'allow_promo_codes' => false,
            'is_active' => true, 'is_stackable' => true, 'priority' => 0,
        ], $attributes));
    }

    private function applicable(Promotion $promotion, Product $product, ?int $variantId): bool
    {
        return app(PromotionService::class)->findApplicablePromotions(
            [['product_id' => $product->id, 'product_variant_id' => $variantId, 'quantity' => 1, 'price' => 1990]],
            1990
        )->contains('id', $promotion->id);
    }

    public function test_trigger_with_selected_variant_matches_only_that_variant(): void
    {
        [$product, $small, $big] = $this->gel();
        $promotion = $this->promotion();
        $promotion->triggerProducts()->attach($product->id, ['variant_ids' => [$small->id]]);

        $this->assertTrue($this->applicable($promotion, $product, $small->id));
        $this->assertFalse($this->applicable($promotion, $product, $big->id));
        $this->assertFalse($this->applicable($promotion, $product, null));
    }

    public function test_trigger_without_variant_selection_matches_any_variant_as_before(): void
    {
        [$product, $small, $big] = $this->gel();
        $promotion = $this->promotion();
        $promotion->triggerProducts()->attach($product->id);

        $this->assertTrue($this->applicable($promotion, $product, $small->id));
        $this->assertTrue($this->applicable($promotion, $product, $big->id));
        $this->assertTrue($this->applicable($promotion, $product, null));
    }

    public function test_public_check_uses_cart_variant_and_offers_only_selected_gift_variant(): void
    {
        [$product, $small, $big] = $this->gel();
        $promotion = $this->promotion(['priority' => 1000]);
        $promotion->triggerProducts()->attach($product->id, ['variant_ids' => [$big->id]]);
        $promotion->giftProducts()->attach($product->id, ['quantity' => 1, 'variant_ids' => [$small->id]]);

        $check = fn (int $variantId) => collect($this->postJson('/api/public/promotions/check-applicable', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variantId, 'quantity' => 1, 'price' => 1990]],
            'total' => 1990,
        ])->assertOk()->json('data'))->firstWhere('id', $promotion->id);

        $this->assertNull($check($small->id));
        $found = $check($big->id);
        $this->assertNotNull($found);
        $this->assertSame([$small->id], array_column($found['gift_products'][0]['variants'], 'id'));
    }

    public function test_checkout_rejects_gift_variant_that_is_not_in_the_promotion(): void
    {
        [$product, $small, $big] = $this->gel();
        $promotion = $this->promotion();
        $promotion->giftProducts()->attach($product->id, ['quantity' => 1, 'variant_ids' => [$small->id]]);

        $order = fn (int $giftVariantId) => $this->postJson('/api/public/orders', [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая улица, 1'],
            'user' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000', 'email' => 'variant-guest@example.com'],
            'recipient' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'],
            'items' => [['product_id' => $product->id, 'product_variant_id' => $small->id, 'quantity' => 1, 'price' => 1990]],
            'promotions' => [['promotion_id' => $promotion->id, 'gift_product_id' => $product->id, 'gift_product_variant_id' => $giftVariantId]],
        ]);

        $order($big->id)->assertStatus(422)->assertJsonValidationErrors('promotions.0.gift_product_variant_id');
        $order($small->id)->assertCreated();
    }

    public function test_admin_saves_keeps_clears_and_copies_variant_selection(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$product, $small, $big] = $this->gel();
        $payload = [
            'name' => 'Admin variants '.uniqid(), 'min_purchase_amount' => 0, 'allow_promo_codes' => false,
            'trigger_product_ids' => [$product->id],
            'trigger_variants' => [$product->id => [$big->id]],
            'gift_products' => [['product_id' => $product->id, 'quantity' => 1, 'variant_ids' => [$small->id]]],
        ];

        $response = $this->postJson('/api/promotions', $payload)->assertCreated();
        $response->assertJsonPath('data.trigger_products.0.selected_variants.0.name', '300 мл')
            ->assertJsonPath('data.gift_products.0.selected_variants.0.name', '100 мл');
        $promotion = Promotion::findOrFail($response->json('data.id'));

        $trigger = fn (Promotion $p) => $p->triggerProducts()->first()->pivot->variant_ids;
        $gift = fn (Promotion $p) => $p->giftProducts()->first()->pivot->variant_ids;
        $this->assertSame([$big->id], $trigger($promotion));
        $this->assertSame([$small->id], $gift($promotion));

        // Старая админка не присылает поля вариантов — выбор не должен пропасть.
        $this->putJson("/api/promotions/{$promotion->id}", [
            'trigger_product_ids' => [$product->id],
            'gift_products' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertOk();
        $this->assertSame([$big->id], $trigger($promotion));
        $this->assertSame([$small->id], $gift($promotion));

        $copyId = $this->postJson("/api/promotions/{$promotion->id}/duplicate")->assertCreated()->json('data.id');
        $copy = Promotion::findOrFail($copyId);
        $this->assertSame([$big->id], $trigger($copy));
        $this->assertSame([$small->id], $gift($copy));

        // Товар целиком: пустой выбор снимает ограничение.
        $this->putJson("/api/promotions/{$promotion->id}", [
            'trigger_product_ids' => [$product->id],
            'trigger_variants' => [],
            'gift_products' => [['product_id' => $product->id, 'quantity' => 1, 'variant_ids' => null]],
        ])->assertOk();
        $this->assertNull($trigger($promotion));
        $this->assertNull($gift($promotion));

        [, $foreign] = $this->gel();
        $this->putJson("/api/promotions/{$promotion->id}", [
            'trigger_product_ids' => [$product->id],
            'trigger_variants' => [$product->id => [$foreign->id]],
        ])->assertStatus(422);
        $this->assertNull($trigger($promotion));
    }
}
