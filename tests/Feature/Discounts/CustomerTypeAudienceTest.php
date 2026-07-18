<?php

namespace Tests\Feature\Discounts;

use App\Models\Client;
use App\Models\Discount;
use App\Models\Product;
use App\Models\PromoCode;
use App\Services\PromoCode\PromoCodeValidationService;
use App\Traits\ProductsTrait;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTypeAudienceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_discount_applies_only_to_guest_customer_type(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_GUEST);
        $product->discounts()->attach($discount->id);

        $guestProduct = Product::find($product->id);
        $this->discountApplier()->applyDiscountToProduct($guestProduct, Discount::CUSTOMER_TYPE_GUEST);

        $this->assertSame(90.0, (float) $guestProduct->price);
        $this->assertSame(10.0, (float) $guestProduct->total_discount);
        $this->assertSame($discount->id, $guestProduct->discount_id);

        $authorizedProduct = Product::find($product->id);
        $this->discountApplier()->applyDiscountToProduct($authorizedProduct, Discount::CUSTOMER_TYPE_AUTHORIZED);

        $this->assertSame(100.0, (float) $authorizedProduct->price);
        $this->assertNull($authorizedProduct->total_discount);
        $this->assertNull($authorizedProduct->discount_id);
    }

    public function test_authorized_discount_applies_only_to_authorized_customer_type(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_AUTHORIZED);
        $product->discounts()->attach($discount->id);

        $guestProduct = Product::find($product->id);
        $this->discountApplier()->applyDiscountToProduct($guestProduct, Discount::CUSTOMER_TYPE_GUEST);

        $this->assertSame(100.0, (float) $guestProduct->price);
        $this->assertNull($guestProduct->total_discount);
        $this->assertNull($guestProduct->discount_id);

        $authorizedProduct = Product::find($product->id);
        $this->discountApplier()->applyDiscountToProduct($authorizedProduct, Discount::CUSTOMER_TYPE_AUTHORIZED);

        $this->assertSame(90.0, (float) $authorizedProduct->price);
        $this->assertSame(10.0, (float) $authorizedProduct->total_discount);
        $this->assertSame($discount->id, $authorizedProduct->discount_id);
    }

    public function test_all_discount_applies_to_guest_and_authorized_customer_types(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_ALL);
        $product->discounts()->attach($discount->id);

        foreach ([Discount::CUSTOMER_TYPE_GUEST, Discount::CUSTOMER_TYPE_AUTHORIZED] as $customerType) {
            $model = Product::find($product->id);
            $this->discountApplier()->applyDiscountToProduct($model, $customerType);

            $this->assertSame(90.0, (float) $model->price);
            $this->assertSame(10.0, (float) $model->total_discount);
            $this->assertSame($discount->id, $model->discount_id);
        }
    }

    public function test_guest_promo_code_is_rejected_for_authorized_client(): void
    {
        $promoCode = $this->promoCode(PromoCode::CUSTOMER_TYPE_GUEST);

        $guestResult = app(PromoCodeValidationService::class)->validate($promoCode->code);
        $authorizedResult = app(PromoCodeValidationService::class)->validate($promoCode->code, $this->client());

        $this->assertTrue($guestResult['success']);
        $this->assertFalse($authorizedResult['success']);
        $this->assertSame('PROMO_ONLY_FOR_GUESTS', $authorizedResult['code']);
    }

    public function test_authorized_promo_code_is_rejected_for_guest(): void
    {
        $promoCode = $this->promoCode(PromoCode::CUSTOMER_TYPE_AUTHORIZED);

        $guestResult = app(PromoCodeValidationService::class)->validate($promoCode->code);
        $authorizedResult = app(PromoCodeValidationService::class)->validate($promoCode->code, $this->client());

        $this->assertFalse($guestResult['success']);
        $this->assertSame('PROMO_REQUIRES_AUTH', $guestResult['code']);
        $this->assertTrue($authorizedResult['success']);
    }

    public function test_public_checkout_rejects_authorized_promo_code_for_guest(): void
    {
        $product = $this->product();
        $promoCode = $this->promoCode(PromoCode::CUSTOMER_TYPE_AUTHORIZED);

        $response = $this->postJson('/api/public/orders', $this->checkoutPayload($product, $promoCode));

        $response->assertUnprocessable()
            ->assertJsonPath('details.code', 'PROMO_REQUIRES_AUTH');
    }

    public function test_public_checkout_rejects_authorized_discount_price_for_guest(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_AUTHORIZED);
        $product->discounts()->attach($discount->id);

        $response = $this->postJson('/api/public/orders', $this->checkoutPayload($product, null, 90));

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'PRICE_MISMATCH')
            ->assertJsonPath('errors.0.actual_price', 100);
    }

    public function test_public_checkout_rejects_guest_promo_code_for_authorized_client(): void
    {
        $product = $this->product();
        $promoCode = $this->promoCode(PromoCode::CUSTOMER_TYPE_GUEST);
        Sanctum::actingAs($this->client());

        $response = $this->postJson('/api/public/orders', $this->checkoutPayload($product, $promoCode));

        $response->assertUnprocessable()
            ->assertJsonPath('details.code', 'PROMO_ONLY_FOR_GUESTS');
    }

    public function test_public_checkout_rejects_guest_discount_price_for_authorized_client(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_GUEST);
        $product->discounts()->attach($discount->id);
        Sanctum::actingAs($this->client());

        $response = $this->postJson('/api/public/orders', $this->checkoutPayload($product, null, 90));

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'PRICE_MISMATCH')
            ->assertJsonPath('errors.0.actual_price', 100);
    }

    public function test_authorized_client_does_not_receive_guest_discount_when_refreshing_cart_prices(): void
    {
        $product = $this->product();
        $discount = $this->discount(Discount::CUSTOMER_TYPE_GUEST);
        $product->discounts()->attach($discount->id);

        Sanctum::actingAs($this->client());

        $response = $this->postJson('/api/public/cart/refresh-prices', [
            'items' => [[
                'product_id' => $product->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('items.0.price', 100)
            ->assertJsonPath('items.0.discount_id', null);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Audience test product '.uniqid(),
            'slug' => 'audience-test-product-'.uniqid(),
            'price' => 100,
            'currency' => 'RUB',
            'is_active' => true,
            'has_variants' => false,
            'stock_quantity' => 10,
        ]);
    }

    private function discount(string $customerType): Discount
    {
        return Discount::create([
            'name' => 'Audience test discount '.uniqid(),
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'customer_type' => $customerType,
            'discount_type' => 'specific',
        ]);
    }

    private function promoCode(string $customerType): PromoCode
    {
        return PromoCode::create([
            'code' => 'AUD'.strtoupper(uniqid()),
            'discount_amount' => 10,
            'discount_type' => 'percentage',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'customer_type' => $customerType,
            'applies_to_all_clients' => true,
            'applies_to_all_products' => true,
            'discount_behavior' => PromoCode::DISCOUNT_BEHAVIOR_REPLACE,
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'email' => 'audience-'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    private function checkoutPayload(Product $product, ?PromoCode $promoCode = null, float $price = 100): array
    {
        $payload = [
            'delivery_address' => [
                'country' => 'Россия',
                'city' => 'Москва',
                'address' => 'Тестовая улица, 1',
            ],
            'user' => [
                'first_name' => 'Тест',
                'last_name' => 'Покупатель',
                'phone' => '+79990000000',
            ],
            'recipient' => [
                'first_name' => 'Тест',
                'last_name' => 'Покупатель',
                'phone' => '+79990000000',
            ],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $price,
            ]],
        ];

        if ($promoCode) {
            $payload['promo_code'] = $promoCode->code;
        }

        return $payload;
    }

    private function discountApplier(): object
    {
        return new class {
            use ProductsTrait;
        };
    }
}
