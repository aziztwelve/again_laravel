<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Services\Export\OrderExportService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class OrderExportServiceTest extends TestCase
{
    public function test_exported_items_use_variant_product_and_legacy_sku_fallbacks(): void
    {
        $variantItem = new OrderItem(['quantity' => 1, 'price' => 100]);
        $variantItem->setRelation('product', (object) ['name' => 'Футболка', 'sku' => 'PRODUCT-SKU']);
        $variantItem->setRelation('variant', (object) ['name' => 'M', 'sku' => 'VARIANT-SKU']);

        $productItem = new OrderItem(['quantity' => 2, 'price' => 200]);
        $productItem->setRelation('product', (object) ['name' => 'Худи', 'sku' => 'PRODUCT-ONLY-SKU']);
        $productItem->setRelation('variant', null);

        $legacyItem = new OrderItem([
            'quantity' => 3,
            'price' => 300,
            'legacy_name' => 'Старый товар',
            'legacy_sku' => 'LEGACY-SKU',
        ]);
        $legacyItem->setRelation('product', null);
        $legacyItem->setRelation('variant', null);

        $order = new Order(['total_amount' => 0, 'discount_amount' => 0, 'delivery_cost' => 0]);
        $order->setRelation('items', new Collection([$variantItem, $productItem, $legacyItem]));
        $order->setRelation('client', null);
        $order->setRelation('address', new OrderAddress());
        $order->setRelation('promoCode', null);
        $order->setRelation('deliveryMethod', null);

        $method = new ReflectionMethod(OrderExportService::class, 'formatRow');
        $row = $method->invoke(new OrderExportService(), $order);

        $this->assertSame(
            'Футболка (M), арт. VARIANT-SKU (x1, 100,00₽), '
            .'Худи, арт. PRODUCT-ONLY-SKU (x2, 400,00₽), '
            .'Старый товар, арт. LEGACY-SKU (x3, 900,00₽)',
            $row[16],
        );
    }
}
