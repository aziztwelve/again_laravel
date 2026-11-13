<?php

namespace Tests\Feature\Delivery;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/** Тот же дефект, что был в СДЭК: вес варианта «0.000» перекрывал вес товара. */
class YandexItemWeightTest extends TestCase
{
    use DatabaseTransactions;

    private function offerItem(?ProductVariant $variant, Product $product, int $quantity = 1): array
    {
        $model = $variant ?? $product;
        if ($variant) {
            $variant->setRelation('product', $product);
        }
        $service = new YandexDeliveryService();

        return (new ReflectionMethod($service, 'offerItem'))->invoke($service, ['model' => $model, 'quantity' => $quantity, 'final_price' => 1000]);
    }

    public function test_zero_variant_weight_falls_back_to_the_product_weight_in_grams(): void
    {
        $product = (new Product())->forceFill(['name' => 'Любимый SET', 'weight' => '0.200', 'length' => '27.00', 'width' => '5.00', 'height' => '24.00']);
        $variant = (new ProductVariant())->forceFill(['sku' => 'again-setds-черный-m', 'weight' => '0.000', 'length' => '0.00', 'width' => '0.00', 'height' => '0.00']);

        $item = $this->offerItem($variant, $product);

        $this->assertSame(200, $item['weight']);
        $this->assertSame(27.0, $item['size']['length']);
        $this->assertSame(24.0, $item['size']['height']);
    }

    public function test_item_without_any_weight_uses_the_default(): void
    {
        $product = (new Product())->forceFill(['name' => 'Без веса', 'weight' => '0.000']);

        $this->assertSame(500, $this->offerItem(null, $product)['weight']);
    }
}
