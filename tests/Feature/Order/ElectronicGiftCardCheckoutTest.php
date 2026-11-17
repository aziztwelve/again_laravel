<?php

namespace Tests\Feature\Order;

use App\Models\DeliveryMethod;
use App\Models\GiftCard\GiftCard;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Order\DigitalOrderDetector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Электронная подарочная карта ничего не везёт: заказ из одних электронных сертификатов оформляется
 * без адреса и доставки, а карта уходит на почту после оплаты. Пластиковая карта и обычные товары
 * в корзине по-прежнему требуют адрес.
 */
class ElectronicGiftCardCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    /** Без адреса сервер возвращает ошибку по каждому из обязательных полей. */
    private const ADDRESS_ERRORS = ['delivery_address.country', 'delivery_address.city', 'delivery_address.address'];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function certificate(float $nominal = 2000): array
    {
        $product = Product::create([
            'name' => DigitalOrderDetector::GIFT_CARD_PRODUCT_NAME, 'price' => $nominal, 'currency' => 'RUB',
            'is_active' => true, 'has_variants' => true, 'stock_quantity' => 100,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Номинал '.$nominal, 'sku' => 'gc-'.uniqid(),
            'price' => $nominal, 'additional_cost' => 0, 'stock_quantity' => 100,
        ]);

        return [$product, $variant];
    }

    private function regularProduct(): Product
    {
        return Product::create([
            'name' => 'Обычный товар '.uniqid(), 'price' => 1500, 'currency' => 'RUB',
            'is_active' => true, 'has_variants' => false, 'stock_quantity' => 100,
        ]);
    }

    private function payload(array $items, array $giftCardData = [], array $extra = []): array
    {
        return array_merge([
            'user' => ['first_name' => 'Анна', 'last_name' => 'Тестова', 'phone' => '+79990000000', 'email' => 'buyer@example.com'],
            'items' => $items,
            'payment_method' => 'card',
            'privacy_policy_accepted' => true,
            'personal_data_consent' => true,
            'oferta_accepted' => true,
            'gift_card_data' => array_merge([
                'type' => GiftCard::TYPE_ELECTRONIC, 'recipient_type' => 'self', 'sender_name' => 'Анна',
                'delivery_channel' => 'email', 'delivery_type' => 'immediate',
            ], $giftCardData),
        ], $extra);
    }

    private function certificateItem(Product $product, ProductVariant $variant, float $price = 2000): array
    {
        return ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'price' => $price];
    }

    public function test_electronic_certificate_is_ordered_without_address_and_delivery(): void
    {
        [$product, $variant] = $this->certificate();

        $response = $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)]))
            ->assertStatus(201);

        $order = Order::findOrFail($response->json('order.id'));
        $this->assertEquals(2000, $order->total_amount);
        $this->assertEquals(0, $order->delivery_cost);
        $this->assertNull($order->delivery_method_id);
        $this->assertNull($order->delivery_data);
        $this->assertNull($order->delivery_date);
        $this->assertNull($order->free_shipping_rule_id);
        $this->assertNull($order->address?->address);
        $this->assertNull($order->address?->city);
        $this->assertSame('Анна', $order->address?->recipient_first_name);

        $card = GiftCard::where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($card);
        $this->assertSame(GiftCard::STATUS_INACTIVE, $card->status);
    }

    public function test_a_stale_storefront_cannot_add_delivery_cost_to_a_certificate(): void
    {
        [$product, $variant] = $this->certificate();
        $method = DeliveryMethod::where('code', 'cdek_courier')->firstOrFail();

        $response = $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)], [], [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая, 1', 'delivery_comment' => 'домофон'],
            'delivery_method' => ['name' => $method->name],
            'delivery_data' => ['provider' => 'cdek', 'price' => 930, 'tariff_code' => 137, 'delivery_type' => 'courier'],
            'pvz_code' => 'MSK123',
        ]))->assertStatus(201);

        $order = Order::findOrFail($response->json('order.id'));
        $this->assertEquals(2000, $order->total_amount);
        $this->assertEquals(0, $order->delivery_cost);
        $this->assertNull($order->delivery_method_id);
        $this->assertNull($order->delivery_data);
        $this->assertNull($order->city_name);
        $this->assertNull($order->address?->address);
    }

    public function test_paying_the_order_activates_the_card_without_any_delivery(): void
    {
        [$product, $variant] = $this->certificate();
        $response = $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)]))->assertStatus(201);

        $order = Order::findOrFail($response->json('order.id'));
        $order->updatePaymentStatus(\App\Enums\PaymentStatus::PAID, 'tx-'.uniqid());

        $card = GiftCard::where('purchase_order_id', $order->id)->firstOrFail();
        $this->assertSame(GiftCard::STATUS_ACTIVE, $card->status);
        Bus::assertDispatched(\App\Jobs\GiftCard\SendGiftCardJob::class, 1);
    }

    public function test_plastic_certificate_still_needs_an_address(): void
    {
        [$product, $variant] = $this->certificate();

        $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)], ['type' => GiftCard::TYPE_PLASTIC]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::ADDRESS_ERRORS);
    }

    public function test_plastic_certificate_with_an_address_is_accepted(): void
    {
        [$product, $variant] = $this->certificate();

        $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)], ['type' => GiftCard::TYPE_PLASTIC], [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая, 1'],
        ]))->assertStatus(201);
    }

    public function test_certificate_together_with_a_regular_product_still_needs_an_address(): void
    {
        [$product, $variant] = $this->certificate();
        $regular = $this->regularProduct();

        $this->postJson('/api/public/orders', $this->payload([
            $this->certificateItem($product, $variant),
            ['product_id' => $regular->id, 'quantity' => 1, 'price' => 1500],
        ]))->assertStatus(422)->assertJsonValidationErrors(self::ADDRESS_ERRORS);
    }

    public function test_regular_order_still_needs_an_address(): void
    {
        $regular = $this->regularProduct();

        $this->postJson('/api/public/orders', $this->payload([['product_id' => $regular->id, 'quantity' => 1, 'price' => 1500]], [], ['gift_card_data' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::ADDRESS_ERRORS);
    }

    public function test_certificate_without_gift_card_data_is_not_treated_as_electronic(): void
    {
        [$product, $variant] = $this->certificate();

        $this->postJson('/api/public/orders', $this->payload([$this->certificateItem($product, $variant)], [], ['gift_card_data' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(self::ADDRESS_ERRORS);
    }

    public function test_detector_rejects_malformed_input(): void
    {
        $detector = app(DigitalOrderDetector::class);
        [$product] = $this->certificate();
        $electronic = ['type' => GiftCard::TYPE_ELECTRONIC];

        $this->assertTrue($detector->isElectronicGiftCardOnly([['product_id' => $product->id]], $electronic));
        $this->assertFalse($detector->isElectronicGiftCardOnly([], $electronic));
        $this->assertFalse($detector->isElectronicGiftCardOnly('junk', $electronic));
        $this->assertFalse($detector->isElectronicGiftCardOnly([['product_id' => 'abc']], $electronic));
        $this->assertFalse($detector->isElectronicGiftCardOnly([['product_id' => 99999999]], $electronic));
        $this->assertFalse($detector->isElectronicGiftCardOnly([['product_id' => $product->id]], null));
        $this->assertFalse($detector->isElectronicGiftCardOnly([['product_id' => $product->id]], ['type' => GiftCard::TYPE_PLASTIC]));
    }
}
