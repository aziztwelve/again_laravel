<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryMethod;
use App\Models\FreeShippingRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Delivery\FreeShipping\FreeShippingContext;
use App\Services\Delivery\FreeShippingService;
use App\Services\Order\OrderUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Бесплатная доставка по гибким правилам — см. docs/tasks/free-shipping.md
 */
class FreeShippingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // В окружении могут быть засеянные правила (FreeShippingRuleSeeder) —
        // выключаем их, чтобы тесты не зависели от данных БД. Транзакция теста
        // откатит изменение.
        FreeShippingRule::query()->update(['is_active' => false]);
    }

    // === Матчинг условий ===

    public function test_rule_without_conditions_applies_to_any_delivery(): void
    {
        $this->rule(['min_order_amount' => 5000]);

        $match = $this->service()->evaluate($this->context(5000));

        $this->assertNotNull($match);
        $this->assertSame(5000.0, $match->qualifyingAmount);
    }

    public function test_amount_below_threshold_is_not_free(): void
    {
        $this->rule(['min_order_amount' => 5000]);

        $this->assertNull($this->service()->evaluate($this->context(4999.99)));
    }

    public function test_service_condition_filters_other_services(): void
    {
        $this->rule(['min_order_amount' => 1000, 'services' => ['cdek']]);

        $this->assertNotNull($this->service()->evaluate($this->context(2000, service: 'cdek')));
        $this->assertNull($this->service()->evaluate($this->context(2000, service: 'yandex')));
        // Служба не выбрана — в строгом режиме условие не выполнено.
        $this->assertNull($this->service()->evaluate($this->context(2000, service: null)));
    }

    public function test_delivery_type_condition_filters_courier(): void
    {
        $this->rule(['min_order_amount' => 1000, 'delivery_types' => ['pickup']]);

        $this->assertNotNull($this->service()->evaluate($this->context(2000, type: 'pickup')));
        $this->assertNull($this->service()->evaluate($this->context(2000, type: 'courier')));
    }

    public function test_postamat_counts_as_pickup(): void
    {
        $this->rule(['min_order_amount' => 1000, 'delivery_types' => ['pickup']]);

        $candidates = $this->service()->evaluateCandidates(
            $this->context(2000, service: null, type: null),
            [['key' => 'cdek:postamat', 'service' => 'cdek', 'delivery_type' => 'postamat', 'price' => 350]]
        );

        $this->assertTrue($candidates[0]['is_free']);
        $this->assertSame('pickup', $candidates[0]['delivery_type']);
        $this->assertSame(0.0, $candidates[0]['price']);
        $this->assertSame(350.0, $candidates[0]['original_price']);
    }

    public function test_payment_method_condition(): void
    {
        $this->rule(['min_order_amount' => 1000, 'payment_methods' => ['cloudpayments_sbp']]);

        $context = $this->context(2000);
        $context->paymentMethod = 'cloudpayments_sbp';
        $this->assertNotNull($this->service()->evaluate($context));

        $context->paymentMethod = 'card_ru';
        $this->assertNull($this->service()->evaluate($context));
    }

    public function test_country_and_region_conditions(): void
    {
        [$countryId, $regionId, $cityId] = $this->geo();

        $rule = $this->rule(['min_order_amount' => 1000]);
        $rule->countries()->sync([$countryId]);
        $rule->regions()->sync([$regionId]);

        // Регион и страна достраиваются из города.
        $context = $this->context(2000);
        $context->cityId = $cityId;
        $this->assertNotNull($this->service()->evaluate($context));

        $foreign = $this->context(2000);
        $foreign->countryId = $countryId + 1;
        $foreign->regionId = $regionId + 1;
        $this->assertNull($this->service()->evaluate($foreign));
    }

    public function test_product_list_limits_qualifying_amount(): void
    {
        $target = $this->product(3000);
        $other = $this->product(4000);

        $rule = $this->rule(['min_order_amount' => 5000]);
        $rule->products()->sync([$target->id]);

        // 3000 (целевой) + 4000 (прочий) = 7000, но считается только целевой.
        $context = new FreeShippingContext(items: [
            ['product_id' => $target->id, 'quantity' => 1, 'price' => 3000.0],
            ['product_id' => $other->id, 'quantity' => 1, 'price' => 4000.0],
        ], service: 'cdek', deliveryType: 'pickup');

        $this->assertNull($this->service()->evaluate($context));

        // Два целевых товара — порог взят.
        $context->items[0]['quantity'] = 2;
        $this->assertNotNull($this->service()->evaluate($context));
    }

    public function test_rule_with_products_requires_one_in_cart(): void
    {
        $target = $this->product(1000);
        $other = $this->product(9000);

        $rule = $this->rule(['min_order_amount' => 1000]);
        $rule->products()->sync([$target->id]);

        $context = new FreeShippingContext(items: [
            ['product_id' => $other->id, 'quantity' => 1, 'price' => 9000.0],
        ], service: 'cdek', deliveryType: 'pickup');

        $this->assertNull($this->service()->evaluate($context));
    }

    public function test_gift_items_are_excluded_from_qualifying_amount(): void
    {
        $this->rule(['min_order_amount' => 5000]);

        $context = new FreeShippingContext(items: [
            ['product_id' => $this->product(4000)->id, 'quantity' => 1, 'price' => 4000.0],
            ['product_id' => $this->product(0)->id, 'quantity' => 1, 'price' => 0.0, 'is_gift' => true],
        ], service: 'cdek', deliveryType: 'pickup');

        $this->assertNull($this->service()->evaluate($context));
    }

    public function test_rule_with_lowest_threshold_wins(): void
    {
        $cheap = $this->rule(['min_order_amount' => 4500, 'name' => 'ПВЗ 4500 '.uniqid()]);
        $this->rule(['min_order_amount' => 3000, 'name' => 'ПВЗ 3000 '.uniqid(), 'is_active' => false]);
        $expensive = $this->rule(['min_order_amount' => 7900, 'name' => 'Курьер 7900 '.uniqid()]);

        $match = $this->service()->evaluate($this->context(8000));

        $this->assertNotNull($match);
        $this->assertSame($cheap->id, $match->ruleId, 'Выключенное правило не учитывается, выигрывает меньший порог');
        $this->assertNotSame($expensive->id, $match->ruleId);
    }

    public function test_inactive_and_expired_rules_are_ignored(): void
    {
        $this->rule(['min_order_amount' => 1000, 'is_active' => false]);
        $this->rule(['min_order_amount' => 1000, 'ends_at' => now()->subDay()]);
        $this->rule(['min_order_amount' => 1000, 'starts_at' => now()->addDay()]);

        $this->assertNull($this->service()->evaluate($this->context(9000)));
    }

    public function test_progress_reports_remaining_amount(): void
    {
        $this->rule(['min_order_amount' => 5000]);

        $progress = $this->service()->progress($this->context(3200, service: null, type: null));

        $this->assertNotNull($progress);
        $this->assertSame(1800.0, $progress['remaining']);
        $this->assertSame(5000.0, $progress['min_order_amount']);
    }

    public function test_progress_shows_next_tier_when_lower_one_is_reached(): void
    {
        $this->rule(['min_order_amount' => 4500, 'name' => 'ПВЗ '.uniqid()]);
        $this->rule(['min_order_amount' => 7900, 'name' => 'Курьер '.uniqid()]);

        $progress = $this->service()->progress($this->context(5000, service: null, type: null));

        $this->assertNotNull($progress);
        $this->assertSame(2900.0, $progress['remaining']);
    }

    // === Применение к заказу ===

    public function test_apply_to_order_zeroes_delivery_and_is_reversible(): void
    {
        $rule = $this->rule(['min_order_amount' => 5000, 'services' => ['cdek'], 'delivery_types' => ['pickup']]);
        $product = $this->product(5000);
        $order = $this->order($product, 5000, 390);

        $match = $this->service()->applyToOrder($order);

        $this->assertNotNull($match);
        $order->refresh();
        $this->assertSame(0.0, (float) $order->delivery_cost);
        $this->assertSame(390.0, (float) $order->delivery_cost_original);
        $this->assertSame($rule->id, $order->free_shipping_rule_id);
        $this->assertSame(5000.0, (float) $order->total_amount);

        // Идемпотентность: повторный вызов не теряет исходную цену тарифа.
        $this->service()->applyToOrder($order);
        $order->refresh();
        $this->assertSame(390.0, (float) $order->delivery_cost_original);

        // Условия перестали выполняться → доставка снова платная.
        $order->items()->update(['price' => 1000]);
        $this->service()->flushCache();
        $this->assertNull($this->service()->applyToOrder($order->refresh()->load('items')));

        $order->refresh();
        $this->assertSame(390.0, (float) $order->delivery_cost);
        $this->assertNull($order->free_shipping_rule_id);
        $this->assertNull($order->delivery_cost_original);
        $this->assertSame(1390.0, (float) $order->total_amount);
    }

    // === Публичный чекаут ===

    public function test_public_checkout_makes_delivery_free(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['yandex'], 'delivery_types' => ['pickup']]);
        $product = $this->product(5000);

        $response = $this->postJson('/api/public/orders', $this->checkoutPayload($product, 5000));

        $response->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        $this->assertSame(0.0, (float) $order->delivery_cost);
        $this->assertSame(390.0, (float) $order->delivery_cost_original);
        $this->assertNotNull($order->free_shipping_rule_id);
        $this->assertSame(5000.0, (float) $order->total_amount);
    }

    /**
     * Ключевое требование заказчика: скидки/промокод считаются в сумме выкупа.
     * Промокод −20% уронил 5000 → 4000, порог 5000 не взят — доставка платная.
     */
    public function test_promo_code_discount_can_make_delivery_paid_again(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['yandex'], 'delivery_types' => ['pickup']]);
        $product = $this->product(5000);
        $promoCode = $this->promoCode(20);

        $payload = $this->checkoutPayload($product, 5000);
        $payload['promo_code'] = $promoCode->code;

        $response = $this->postJson('/api/public/orders', $payload);

        $response->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        $this->assertSame(4000.0, (float) $order->items->sum(fn ($i) => $i->quantity * $i->price));
        $this->assertSame(390.0, (float) $order->delivery_cost);
        $this->assertNull($order->free_shipping_rule_id);
        $this->assertSame(4390.0, (float) $order->total_amount);
    }

    // === Пересчёт при редактировании заказа в админке ===

    /**
     * Менеджер убрал товары из заказа — сумма выкупа упала ниже порога,
     * доставка снова платная (решение заказчика от 2026-08-18).
     */
    public function test_order_update_recalculates_free_shipping_when_items_change(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['cdek'], 'delivery_types' => ['pickup']]);
        $product = $this->product(1000);
        $order = $this->order($product, 1000, 390);

        // 5 × 1000 = 5000 → доставка бесплатна
        $order->items()->update(['quantity' => 5]);
        $this->service()->applyToOrder($order->refresh()->load('items'));
        $this->assertSame(0.0, (float) $order->refresh()->delivery_cost);

        // Менеджер оставил 2 штуки: 2000 < 5000 → доставка снова платная
        app(OrderUpdateService::class)->update($order, [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 1000,
            ]],
        ]);

        $order->refresh();
        $this->assertSame(390.0, (float) $order->delivery_cost);
        $this->assertNull($order->free_shipping_rule_id);
        $this->assertNull($order->delivery_cost_original);
        $this->assertSame(2390.0, (float) $order->total_amount);
    }

    /** Добавили товаров — порог взят, доставка становится бесплатной. */
    public function test_order_update_makes_delivery_free_when_items_grow(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['cdek'], 'delivery_types' => ['pickup']]);
        $product = $this->product(1000);
        $order = $this->order($product, 1000, 390);

        app(OrderUpdateService::class)->update($order, [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 6,
                'price' => 1000,
            ]],
        ]);

        $order->refresh();
        $this->assertSame(0.0, (float) $order->delivery_cost);
        $this->assertSame(390.0, (float) $order->delivery_cost_original);
        $this->assertNotNull($order->free_shipping_rule_id);
        $this->assertSame(6000.0, (float) $order->total_amount);
    }

    /**
     * Менеджер вручную поменял стоимость доставки: она становится новой
     * тарифной базой и возвращается, когда правило перестаёт подходить.
     */
    public function test_manual_delivery_cost_becomes_new_tariff_base(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['cdek'], 'delivery_types' => ['pickup']]);
        $product = $this->product(1000);
        $order = $this->order($product, 1000, 390);

        // 6000 ₽ + менеджер выставил доставку 800 ₽ → правило сработало (0 ₽),
        // но в базе сохранено 800 как тариф.
        app(OrderUpdateService::class)->update($order, [
            'items' => [['product_id' => $product->id, 'quantity' => 6, 'price' => 1000]],
            'delivery_cost' => 800,
        ]);

        $order->refresh();
        $this->assertSame(0.0, (float) $order->delivery_cost);
        $this->assertSame(800.0, (float) $order->delivery_cost_original);

        // Убрали товары → возвращается именно 800, а не первоначальные 390.
        app(OrderUpdateService::class)->update($order, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]],
        ]);

        $order->refresh();
        $this->assertSame(800.0, (float) $order->delivery_cost);
        $this->assertNull($order->free_shipping_rule_id);
        $this->assertSame(1800.0, (float) $order->total_amount);
    }

    /** Правка полей, не влияющих на условия, стоимость доставки не трогает. */
    public function test_unrelated_order_update_keeps_delivery_cost(): void
    {
        $this->rule(['min_order_amount' => 100000, 'services' => ['cdek']]);
        $product = $this->product(1000);
        $order = $this->order($product, 1000, 390);

        app(OrderUpdateService::class)->update($order, [
            'seller_comment' => 'Позвонить после 18:00',
        ]);

        $order->refresh();
        $this->assertSame(390.0, (float) $order->delivery_cost);
        $this->assertNull($order->delivery_cost_original);
    }

    // === Публичный endpoint оценки ===

    public function test_public_evaluate_endpoint_marks_free_candidates(): void
    {
        $this->rule(['min_order_amount' => 5000, 'services' => ['cdek'], 'delivery_types' => ['pickup']]);
        $product = $this->product(5000);

        $response = $this->postJson('/api/public/delivery/free-shipping/evaluate', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'candidates' => [
                ['key' => 'cdek:pvz', 'service' => 'cdek', 'delivery_type' => 'pickup', 'price' => 390],
                ['key' => 'cdek:courier', 'service' => 'cdek', 'delivery_type' => 'courier', 'price' => 590],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('qualifying_amount', fn ($value) => (float) $value === 5000.0)
            ->assertJsonPath('candidates.0.is_free', true)
            ->assertJsonPath('candidates.0.price', fn ($value) => (float) $value === 0.0)
            ->assertJsonPath('candidates.1.is_free', false)
            ->assertJsonPath('candidates.1.price', fn ($value) => (float) $value === 590.0);
    }

    // === Админский CRUD ===

    public function test_admin_can_create_and_update_rule(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->product(1000);
        [$countryId, $regionId] = $this->geo();

        $created = $this->postJson('/api/free-shipping-rules', [
            'name' => 'Доставка_СДЭК_ПВЗ '.uniqid(),
            'min_order_amount' => 5000,
            'services' => ['cdek'],
            'delivery_types' => ['pickup'],
            'payment_methods' => ['cloudpayments_sbp'],
            'product_ids' => [$product->id],
            'country_ids' => [$countryId],
            'region_ids' => [$regionId],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.services', ['cdek'])
            ->assertJsonPath('data.product_ids', [$product->id])
            ->assertJsonPath('data.country_ids', [$countryId])
            // Дефолт БД: новое правило сразу активно (в ответе тоже).
            ->assertJsonPath('data.is_active', true);

        $ruleId = $created->json('data.id');

        $updated = $this->putJson("/api/free-shipping-rules/{$ruleId}", [
            'min_order_amount' => 7900,
            'services' => [],
            'product_ids' => [],
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.min_order_amount', fn ($value) => (float) $value === 7900.0)
            ->assertJsonPath('data.services', [])
            ->assertJsonPath('data.product_ids', []);

        $this->assertNull(FreeShippingRule::findOrFail($ruleId)->services);
    }

    public function test_admin_rule_validation_rejects_unknown_codes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/free-shipping-rules', [
            'name' => 'Плохое правило',
            'min_order_amount' => 100,
            'services' => ['boxberry'],
        ])->assertUnprocessable()->assertJsonValidationErrors('services.0');
    }

    public function test_rules_endpoint_requires_auth(): void
    {
        $this->getJson('/api/free-shipping-rules')->assertUnauthorized();
    }

    // === Хелперы ===

    private function service(): FreeShippingService
    {
        // Свежий инстанс: у сервиса есть кеш активных правил на запрос.
        return app()->makeWith(FreeShippingService::class, []);
    }

    private function rule(array $attrs = []): FreeShippingRule
    {
        $this->service()->flushCache();

        return FreeShippingRule::create(array_merge([
            'name' => 'FS rule '.uniqid(),
            'is_active' => true,
            'priority' => 0,
            'min_order_amount' => 5000,
        ], $attrs));
    }

    private function context(
        float $amount,
        ?string $service = 'cdek',
        ?string $type = 'pickup'
    ): FreeShippingContext {
        return new FreeShippingContext(
            items: [[
                'product_id' => $this->product(max(1, $amount))->id,
                'quantity' => 1,
                'price' => $amount,
            ]],
            service: $service,
            deliveryType: $type,
        );
    }

    private function product(float $price): Product
    {
        return Product::create([
            'name' => 'FS product '.uniqid(),
            'price' => $price,
            'currency' => 'RUB',
            'is_active' => true,
            'has_variants' => false,
            'stock_quantity' => 100,
        ]);
    }

    private function promoCode(float $percent): PromoCode
    {
        return PromoCode::create([
            'code' => 'FS'.strtoupper(uniqid()),
            'discount_amount' => $percent,
            'discount_type' => 'percentage',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'applies_to_all_clients' => true,
            'applies_to_all_products' => true,
            'discount_behavior' => PromoCode::DISCOUNT_BEHAVIOR_REPLACE,
        ]);
    }

    /**
     * Заказ с одной позицией и платной доставкой СДЭК ПВЗ.
     */
    private function order(Product $product, float $price, float $deliveryCost): Order
    {
        $method = DeliveryMethod::where('code', 'cdek_pickup')->first();

        $order = Order::create([
            'order_number' => 'FS-'.uniqid(),
            'status' => 'new',
            'payment_status' => 'pending',
            'total_amount' => $price + $deliveryCost,
            'delivery_method_id' => $method?->id,
            'delivery_cost' => $deliveryCost,
            'delivery_data' => [
                'provider' => 'cdek',
                'delivery_type' => 'pickup',
                'price' => $deliveryCost,
            ],
            'created_at' => now(),
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $price,
            'discount' => 0,
        ]);

        return $order->load('items');
    }

    /**
     * Гео-справочники: страна → регион → город.
     * Таблицы legacy (`country`/`region`/`city`) без timestamps и с
     * подписанным bigint id, поэтому вставляем напрямую.
     */
    private function geo(): array
    {
        $countryId = (int) DB::table('country')->insertGetId([
            'name' => 'FS страна '.uniqid(),
            'code' => 'F'.random_int(10, 99),
        ]);

        $regionId = (int) DB::table('region')->insertGetId([
            'country_id' => $countryId,
            'name' => 'FS регион '.uniqid(),
        ]);

        $cityId = (int) DB::table('city')->insertGetId([
            'region_id' => $regionId,
            'name' => 'FS город '.uniqid(),
        ]);

        return [$countryId, $regionId, $cityId];
    }

    /**
     * Payload гостевого чекаута с ПВЗ Яндекс.Доставки.
     *
     * Яндекс выбран намеренно: для СДЭК PublicCheckoutController вызывает
     * revalidateCheckout() с обращением к внешнему API — в тестах это недоступно.
     */
    private function checkoutPayload(Product $product, float $price): array
    {
        return [
            'delivery_address' => [
                'country' => 'Россия',
                'city' => 'Москва',
                'address' => 'Тестовая улица, 1',
            ],
            'user' => [
                'first_name' => 'Тест',
                'last_name' => 'Покупатель',
                'phone' => '+79990000000',
                'email' => 'fs-'.uniqid().'@example.com',
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
            'delivery_method' => ['name' => 'Пункт самовывоза Яндекс.Доставки'],
            'delivery_data' => [
                'provider' => 'yandex',
                'delivery_type' => 'pickup',
                'offer_id' => 'fs-test-offer',
                'price' => 390,
                'pvz' => ['id' => 'YND-1', 'address' => 'Москва, Тестовая, 1'],
            ],
            'payment_method' => 'card_ru',
        ];
    }
}
