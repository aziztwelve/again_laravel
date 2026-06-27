<?php

namespace Tests\Feature\Promotion;

use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PromotionStackingTest extends TestCase
{
    use DatabaseTransactions;

    private function makePromotion(array $attrs = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Promo '.uniqid(),
            'min_purchase_amount' => 0,
            'allow_promo_codes' => false,
            'is_stackable' => false,
            'is_active' => true,
            'priority' => 0,
        ], $attrs));
    }

    private function makeGiftProduct(int $stock = 100): Product
    {
        // ProductFactory устарела (шлёт несуществующую колонку is_available),
        // поэтому создаём товар напрямую. slug проставит observer модели.
        return Product::create([
            'name' => 'Gift '.uniqid(),
            'is_active' => true,
            'has_variants' => false,
            'price' => 500,
            'stock_quantity' => $stock,
        ]);
    }

    private function service(): PromotionService
    {
        return app(PromotionService::class);
    }

    /** Две стекируемые применимые акции → применяются обе. */
    public function test_resolve_returns_all_stackable_promotions(): void
    {
        $p1 = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => true, 'priority' => 5]);
        $p2 = $this->makePromotion(['min_purchase_amount' => 4000, 'is_stackable' => true, 'priority' => 1]);

        $p1->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);
        $p2->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);

        $resolved = $this->service()->findResolvedPromotions(
            [['product_id' => $this->makeGiftProduct()->id, 'quantity' => 1, 'price' => 4500]],
            4500
        );

        $ids = $resolved->pluck('id')->all();
        $this->assertContains($p1->id, $ids);
        $this->assertContains($p2->id, $ids);
        $this->assertCount(2, $resolved);
    }

    /** Ниже порога второй акции → применяется только первая. */
    public function test_resolve_respects_min_purchase_threshold(): void
    {
        $p1 = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => true]);
        $p2 = $this->makePromotion(['min_purchase_amount' => 4000, 'is_stackable' => true]);
        $p1->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);
        $p2->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);

        $resolved = $this->service()->findResolvedPromotions(
            [['product_id' => $this->makeGiftProduct()->id, 'quantity' => 1, 'price' => 1500]],
            1500
        );

        $ids = $resolved->pluck('id')->all();
        $this->assertContains($p1->id, $ids);
        $this->assertNotContains($p2->id, $ids);
    }

    /** Нет стекируемых → берётся одна по приоритету (старое поведение). */
    public function test_resolve_returns_single_top_priority_when_none_stackable(): void
    {
        $low = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => false, 'priority' => 1]);
        $high = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => false, 'priority' => 99]);
        $low->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);
        $high->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);

        $resolved = $this->service()->findResolvedPromotions(
            [['product_id' => $this->makeGiftProduct()->id, 'quantity' => 1, 'price' => 1500]],
            1500
        );

        $this->assertCount(1, $resolved);
        $this->assertEquals($high->id, $resolved->first()->id);
    }

    /** Стекируемые присутствуют → невзаимные пропускаются. */
    public function test_resolve_skips_non_stackable_when_stackable_present(): void
    {
        $stackable = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => true]);
        $exclusive = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => false, 'priority' => 99]);
        $stackable->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);
        $exclusive->giftProducts()->attach($this->makeGiftProduct()->id, ['quantity' => 1]);

        $resolved = $this->service()->findResolvedPromotions(
            [['product_id' => $this->makeGiftProduct()->id, 'quantity' => 1, 'price' => 1500]],
            1500
        );

        $ids = $resolved->pluck('id')->all();
        $this->assertContains($stackable->id, $ids);
        $this->assertNotContains($exclusive->id, $ids);
    }

    /** Применение нескольких акций к заказу → несколько подарков, usages, times_used. */
    public function test_apply_multiple_promotions_adds_multiple_gifts(): void
    {
        $gift1 = $this->makeGiftProduct(100);
        $gift2 = $this->makeGiftProduct(100);

        $p1 = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => true]);
        $p2 = $this->makePromotion(['min_purchase_amount' => 4000, 'is_stackable' => true]);
        $p1->giftProducts()->attach($gift1->id, ['quantity' => 1]);
        $p2->giftProducts()->attach($gift2->id, ['quantity' => 2]);

        $order = Order::factory()->create();

        $this->service()->applyPromotionsToOrder($order, [
            ['promotion_id' => $p1->id, 'gift_product_id' => $gift1->id, 'use_discount_instead' => false],
            ['promotion_id' => $p2->id, 'gift_product_id' => $gift2->id, 'use_discount_instead' => false],
        ]);

        $giftItems = $order->items()->where('is_gift', true)->get();
        $this->assertCount(2, $giftItems);
        $this->assertEqualsCanonicalizing(
            [$gift1->id, $gift2->id],
            $giftItems->pluck('product_id')->all()
        );
        $this->assertEquals(0.0, (float) $giftItems->sum('price'));

        $this->assertEquals(2, PromotionUsage::where('order_id', $order->id)->count());
        $this->assertEquals(1, $p1->fresh()->times_used);
        $this->assertEquals(1, $p2->fresh()->times_used);

        // Остатки списаны по факту отгрузки (1 и 2 шт.)
        $this->assertEquals(99, (float) $gift1->fresh()->stock_quantity);
        $this->assertEquals(98, (float) $gift2->fresh()->stock_quantity);
    }

    /** Отмена акций у заказа откатывает подарки/usages/times_used. */
    public function test_cancel_promotions_rolls_back_everything(): void
    {
        $gift1 = $this->makeGiftProduct(100);
        $gift2 = $this->makeGiftProduct(100);

        $p1 = $this->makePromotion(['min_purchase_amount' => 1000, 'is_stackable' => true]);
        $p2 = $this->makePromotion(['min_purchase_amount' => 4000, 'is_stackable' => true]);
        $p1->giftProducts()->attach($gift1->id, ['quantity' => 1]);
        $p2->giftProducts()->attach($gift2->id, ['quantity' => 1]);

        $order = Order::factory()->create();
        $this->service()->applyPromotionsToOrder($order, [
            ['promotion_id' => $p1->id, 'gift_product_id' => $gift1->id, 'use_discount_instead' => false],
            ['promotion_id' => $p2->id, 'gift_product_id' => $gift2->id, 'use_discount_instead' => false],
        ]);

        $this->service()->cancelPromotionFromOrder($order->fresh());

        $this->assertEquals(0, $order->items()->where('is_gift', true)->count());
        $this->assertEquals(0, PromotionUsage::where('order_id', $order->id)->count());
        $this->assertEquals(0, $p1->fresh()->times_used);
        $this->assertEquals(0, $p2->fresh()->times_used);
    }
}
