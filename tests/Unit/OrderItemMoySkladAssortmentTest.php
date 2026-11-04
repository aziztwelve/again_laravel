<?php

namespace Tests\Unit;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Tests\TestCase;

class OrderItemMoySkladAssortmentTest extends TestCase
{
    private function item(?string $variantUuid, ?string $productUuid, bool $hasVariantId): OrderItem
    {
        $item = (new OrderItem)->forceFill(["product_variant_id" => $hasVariantId ? 5 : null]);
        $item->setRelation('variant', $variantUuid ? (new ProductVariant)->forceFill(["uuid" => $variantUuid]) : null);
        $item->setRelation('product', $productUuid ? (new Product)->forceFill(["uuid" => $productUuid]) : null);

        return $item;
    }

    public function test_variant_wins(): void
    {
        $this->assertSame(['type' => 'variant', 'uuid' => 'v'], $this->item('v', 'p', true)->moyskladAssortment());
    }

    public function test_simple_product_without_variant_uses_product(): void
    {
        $this->assertSame(['type' => 'product', 'uuid' => 'p'], $this->item(null, 'p', false)->moyskladAssortment());
    }

    public function test_variant_without_uuid_is_skipped(): void
    {
        $this->assertNull($this->item(null, 'p', true)->moyskladAssortment());
    }
}
