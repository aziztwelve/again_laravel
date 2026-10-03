<?php

namespace Tests\Feature\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\MoySklad\ProductsAndVariantsSyncWithMoySkladService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Товары, отсутствующие в полном списке МойСклад, скрываются с витрины
 * деактивацией, а не копятся вечно в «Скоро в продаже» с нулевым остатком
 * (так в разделе накопились дубли старого аккаунта МС — 19 из 20 записей).
 *
 * Метод вызывается через reflection: полный прогон
 * sync_products_with_moysklad() ходит в реальное API МойСклад.
 */
class RemoveDeletedProductsTest extends TestCase
{
    use DatabaseTransactions;

    private ProductsAndVariantsSyncWithMoySkladService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DeliveryServiceSetting::updateOrCreate(
            ['service_name' => 'moysklad'],
            ['token' => 'test-token'],
        );

        $this->service = new ProductsAndVariantsSyncWithMoySkladService();
    }

    private function invoke(array $moyskladProductUUIDs): void
    {
        $method = new ReflectionMethod($this->service, 'removeDeletedProducts');
        $method->setAccessible(true);
        $method->invoke($this->service, $moyskladProductUUIDs);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Тестовый товар '.uniqid(),
            'is_active' => true,
            'stock_quantity' => 5,
            'price' => 1000,
            'uuid' => (string) Str::uuid(),
        ], $attrs));
    }

    public function test_deactivates_product_missing_from_moysklad_and_zeroes_stock(): void
    {
        $product = $this->product(['stock_quantity' => 8, 'has_variants' => true]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Остаток',
            'sku' => 'gone-'.uniqid(),
            'price' => 1000,
            'stock_quantity' => 8,
        ]);

        $this->invoke([(string) Str::uuid()]);

        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, (int) $variant->fresh()->stock_quantity);
    }

    public function test_keeps_product_present_in_moysklad_untouched(): void
    {
        $product = $this->product(['stock_quantity' => 8]);

        $this->invoke([$product->uuid]);

        $fresh = $product->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(8, (int) $fresh->stock_quantity);
    }

    public function test_empty_moysklad_list_deactivates_nothing(): void
    {
        $product = $this->product();

        $this->invoke([]);

        $fresh = $product->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(5, (int) $fresh->stock_quantity);
    }

    public function test_locally_created_product_without_uuid_is_untouched(): void
    {
        $product = $this->product(['uuid' => null]);

        $this->invoke([(string) Str::uuid()]);

        $fresh = $product->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(5, (int) $fresh->stock_quantity);
    }

    public function test_soft_deleted_missing_product_stays_hidden_and_deactivated(): void
    {
        $product = $this->product();
        $product->delete();

        $this->invoke([(string) Str::uuid()]);

        $fresh = $product->fresh();
        // Не восстанавливаем: записи нет в МС, ей нечего показывать на витрине.
        $this->assertTrue($fresh->trashed());
        $this->assertFalse($fresh->is_active);
    }
}
