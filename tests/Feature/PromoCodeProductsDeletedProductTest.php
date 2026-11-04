<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\PromoCodeProductController;
use App\Models\PromoCode;
use App\Models\PromoCodeProduct;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class PromoCodeProductsDeletedProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deleted_product_binding_does_not_break_promo_edit(): void
    {
        $promo = PromoCode::query()->first() ?? $this->markTestSkipped('нет промокодов');
        $product = Product::query()->first() ?? $this->markTestSkipped('нет товаров');
        PromoCodeProduct::create(['promo_code_id' => $promo->id, 'product_id' => $product->id]);
        $product->delete();

        $response = app(PromoCodeProductController::class)
            ->getProductsByPromoCode(Request::create('/', 'GET', ['withVariants' => 1]), (string) $promo->id);

        $this->assertTrue($response->getData(true)['success']);
    }
}
