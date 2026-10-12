<?php

namespace Tests\Feature\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Models\Product;
use App\Services\MoySklad\ProductsAndVariantsSyncWithMoySkladService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class MoySkladProductDescriptionTest extends TestCase
{
    use DatabaseTransactions;

    private ProductsAndVariantsSyncWithMoySkladService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DeliveryServiceSetting::firstOrCreate(
            ['service_name' => 'moysklad'],
            ['token' => 'test-token'],
        );
        $this->service = new ProductsAndVariantsSyncWithMoySkladService();
    }

    private function sync(object $data, int $stock = 7): Product
    {
        return (new ReflectionMethod($this->service, 'upsertProduct'))->invoke(
            $this->service, $data, [$data->id => ['stock' => $stock]], [],
        );
    }

    private function source(): object
    {
        return (object) [
            'id' => (string) Str::uuid(),
            'name' => 'Description sync test '.Str::uuid(),
            'code' => 'description-'.Str::uuid(),
            'description' => "Plain text from MoySklad\nSecond line",
            'salePrices' => [(object) ['value' => 150000]],
        ];
    }

    public function test_first_import_copies_description_then_preserves_local_formatting(): void
    {
        $data = $this->source();
        $product = $this->sync($data);
        $this->assertSame($data->description, $product->description);

        $html = '<p>First paragraph</p><p><strong>Second paragraph</strong><br>New line</p><ul><li>Item</li></ul>';
        $product->update(['description' => $html]);
        $data->salePrices[0]->value = 249000;
        $data->description = 'Replacement without formatting';
        $this->sync($data, 12);
        $this->sync($data, 15);

        $fresh = $product->fresh();
        $this->assertSame($html, $fresh->description);
        $this->assertSame('2490.00', $fresh->price);
        $this->assertSame(15, (int) $fresh->stock_quantity);
    }

    public function test_existing_empty_descriptions_are_not_refilled(): void
    {
        foreach ([null, ''] as $description) {
            $data = $this->source();
            $product = Product::create([
                'uuid' => $data->id, 'name' => $data->name,
                'description' => $description, 'price' => 100,
            ]);
            $this->sync($data);
            $this->assertSame($description, $product->fresh()->description);
        }
    }

    public function test_product_matched_by_code_keeps_description_when_uuid_changes(): void
    {
        $data = $this->source();
        $product = Product::create([
            'uuid' => (string) Str::uuid(), 'code' => $data->code,
            'name' => $data->name, 'description' => '<p>Edited locally</p>', 'price' => 100,
        ]);
        $synced = $this->sync($data);
        $this->assertSame($product->id, $synced->id);
        $this->assertSame($data->id, $product->fresh()->uuid);
        $this->assertSame('<p>Edited locally</p>', $product->fresh()->description);
    }
}
