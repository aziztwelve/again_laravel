<?php

namespace Tests\Feature\PromoCode;

use App\Models\Client;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Promotion;
use App\Models\Segments\Segment;
use App\Models\User;
use App\Services\PromoCode\PromoCodeValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Промокод, привязанный к сегменту, доступен только клиентам из активного сегмента: нельзя ввести в корзине,
 * применить при оформлении и увидеть в списке профиля. Признак «Ко всем клиентам» сегмент не перекрывает.
 * Менеджер добавляет такой промокод в заказ вручную, как и раньше.
 */
class SegmentPromoCodeAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function client(): Client
    {
        return Client::create(['email' => 'seg-'.uniqid().'@example.com', 'password' => bcrypt('secret')]);
    }

    private function segment(array $members = [], bool $active = true): Segment
    {
        $segment = Segment::create(['name' => 'Segment '.uniqid(), 'is_active' => $active, 'customer_type' => 'authorized']);
        foreach ($members as $member) {
            $segment->clients()->attach($member->id, ['added_at' => now(), 'is_manual' => true]);
        }

        return $segment;
    }

    private function promo(array $segments = [], array $attributes = []): PromoCode
    {
        $promo = PromoCode::create(array_merge([
            'code' => 'SEG'.strtoupper(uniqid()),
            'discount_amount' => 10,
            'discount_type' => 'percentage',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'type' => 'all',
            'customer_type' => PromoCode::CUSTOMER_TYPE_ALL,
            'applies_to_all_clients' => true,
            'applies_to_all_products' => true,
            'discount_behavior' => PromoCode::DISCOUNT_BEHAVIOR_REPLACE,
        ], $attributes));
        foreach ($segments as $segment) {
            $promo->segments()->attach($segment->id, ['auto_apply' => true]);
        }

        return $promo;
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Segment promo product '.uniqid(), 'slug' => 'segment-promo-product-'.uniqid(), 'price' => 100,
            'currency' => 'RUB', 'is_active' => true, 'has_variants' => false, 'stock_quantity' => 10,
        ]);
    }

    private function validateUrl(PromoCode $promo, Product $product, array $extra = []): string
    {
        return '/api/promo-codes/validate?'.http_build_query(array_merge([
            'code' => $promo->code,
            'product_ids' => [$product->id => ''],
        ], $extra));
    }

    private function check(PromoCode $promo, ?Client $client, bool $staff = false): array
    {
        return app(PromoCodeValidationService::class)->validate($promo->code, $client, $staff);
    }

    // ── сервис проверки (оформление заказа, оценка бесплатной доставки, правка заказа)

    public function test_member_can_use_a_segment_promo_code_and_others_cannot(): void
    {
        $member = $this->client();
        $other = $this->client();
        $promo = $this->promo([$this->segment([$member])]);

        $this->assertTrue($this->check($promo, $member)['success']);

        $rejected = $this->check($promo, $other);
        $this->assertFalse($rejected['success']);
        $this->assertSame('PROMO_NOT_FOR_CLIENT', $rejected['code']);

        $guest = $this->check($promo, null);
        $this->assertFalse($guest['success']);
        $this->assertSame('PROMO_REQUIRES_AUTH', $guest['code']);
    }

    public function test_the_all_clients_flag_does_not_override_the_segment(): void
    {
        $other = $this->client();
        $promo = $this->promo([$this->segment()], ['applies_to_all_clients' => true]);

        $this->assertFalse($this->check($promo, $other)['success']);
    }

    public function test_promo_code_without_segments_is_not_affected(): void
    {
        $promo = $this->promo();

        $this->assertTrue($this->check($promo, $this->client())['success']);
        $this->assertTrue($this->check($promo, null)['success']);
    }

    public function test_membership_in_any_one_of_several_segments_is_enough(): void
    {
        $member = $this->client();
        $promo = $this->promo([$this->segment(), $this->segment([$member])]);

        $this->assertTrue($this->check($promo, $member)['success']);
    }

    public function test_a_disabled_segment_does_not_give_access(): void
    {
        $member = $this->client();
        $promo = $this->promo([$this->segment([$member], false)]);

        $this->assertFalse($this->check($promo, $member)['success']);
    }

    public function test_a_client_who_leaves_the_segment_loses_access(): void
    {
        $member = $this->client();
        $segment = $this->segment([$member]);
        $promo = $this->promo([$segment]);
        $this->assertTrue($this->check($promo, $member)['success']);

        $segment->clients()->detach($member->id);

        $this->assertFalse($this->check($promo, $member)['success']);
    }

    public function test_manager_can_still_add_a_segment_promo_code_by_hand(): void
    {
        $outsider = $this->client();
        $promo = $this->promo([$this->segment()]);

        $this->assertTrue($this->check($promo, $outsider, true)['success']);
    }

    // ── публичная проверка в корзине

    public function test_cart_validation_accepts_a_member_and_rejects_an_outsider_and_a_guest(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $product = $this->product();
        $promo = $this->promo([$this->segment([$member])]);

        Sanctum::actingAs($member);
        $this->getJson($this->validateUrl($promo, $product))->assertOk();

        Sanctum::actingAs($outsider);
        $this->getJson($this->validateUrl($promo, $product))->assertStatus(400)->assertJsonPath('code', 'PROMO_NOT_FOR_CLIENT');

        $this->app['auth']->forgetGuards();
        $this->getJson($this->validateUrl($promo, $product))->assertStatus(400)->assertJsonPath('code', 'PROMO_REQUIRES_AUTH');
    }

    public function test_cart_validation_ignores_a_substituted_client_id_of_a_customer_or_a_guest(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $product = $this->product();
        $promo = $this->promo([$this->segment([$member])]);

        Sanctum::actingAs($outsider);
        $this->getJson($this->validateUrl($promo, $product, ['client_id' => $member->id]))
            ->assertStatus(400)->assertJsonPath('code', 'PROMO_NOT_FOR_CLIENT');

        $this->app['auth']->forgetGuards();
        $this->getJson($this->validateUrl($promo, $product, ['client_id' => $member->id]))
            ->assertStatus(400)->assertJsonPath('code', 'PROMO_REQUIRES_AUTH');
    }

    public function test_cart_validation_for_a_manager_works_for_any_chosen_client(): void
    {
        $outsider = $this->client();
        $product = $this->product();
        $promo = $this->promo([$this->segment()]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->validateUrl($promo, $product, ['client_id' => $outsider->id]))->assertOk();
    }

    // ── оформление заказа

    private function checkoutPayload(Product $product, PromoCode $promo): array
    {
        return [
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая улица, 1'],
            'user' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000', 'email' => 'segment-guest@example.com'],
            'recipient' => ['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100]],
            'promo_code' => $promo->code,
        ];
    }

    public function test_checkout_rejects_a_segment_promo_code_for_a_guest_and_for_an_outsider(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $product = $this->product();
        $promo = $this->promo([$this->segment([$member])]);

        $this->postJson('/api/public/orders', $this->checkoutPayload($product, $promo))
            ->assertUnprocessable()->assertJsonPath('details.code', 'PROMO_REQUIRES_AUTH');

        Sanctum::actingAs($outsider);
        $this->postJson('/api/public/orders', $this->checkoutPayload($product, $promo))
            ->assertUnprocessable()->assertJsonPath('details.code', 'PROMO_NOT_FOR_CLIENT');
    }

    // ── список «Мои акции» в профиле

    private function listedCodes(array $query): array
    {
        return collect($this->getJson('/api/promo-code-clients/available-promo-codes?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('code')->all();
    }

    public function test_profile_list_shows_a_segment_promo_code_only_to_members(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $promo = $this->promo([$this->segment([$member])]);
        $open = $this->promo();

        Sanctum::actingAs($member);
        $this->assertContains($promo->code, $this->listedCodes(['client_id' => $member->id, 'per_page' => 500]));

        Sanctum::actingAs($outsider);
        $codes = $this->listedCodes(['client_id' => $outsider->id, 'per_page' => 500]);
        $this->assertNotContains($promo->code, $codes);
        $this->assertContains($open->code, $codes, 'обычный промокод без сегмента остаётся виден');
    }

    public function test_profile_list_ignores_a_substituted_client_id_of_a_customer(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $promo = $this->promo([$this->segment([$member])]);

        Sanctum::actingAs($outsider);
        $this->assertNotContains($promo->code, $this->listedCodes(['client_id' => $member->id, 'per_page' => 500]));
    }

    public function test_profile_list_for_a_manager_shows_what_the_chosen_client_sees(): void
    {
        $member = $this->client();
        $promo = $this->promo([$this->segment([$member])]);

        Sanctum::actingAs(User::factory()->create());
        $this->assertContains($promo->code, $this->listedCodes(['client_id' => $member->id, 'per_page' => 500]));
    }

    // ── акции: то же правило уже действует, закрепляем его

    private function promotion(array $segments = [], bool $active = true): Promotion
    {
        $promotion = Promotion::create([
            'name' => 'Segment promotion '.uniqid(), 'min_purchase_amount' => 0, 'allow_promo_codes' => false,
            'is_stackable' => false, 'is_active' => $active, 'priority' => 0,
        ]);
        foreach ($segments as $segment) {
            $promotion->segments()->attach($segment->id);
        }

        return $promotion;
    }

    public function test_a_promotion_with_a_segment_is_available_only_to_its_members(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $promotion = $this->promotion([$this->segment([$member])]);

        $this->assertTrue($promotion->isAvailableForClient($member->id));
        $this->assertFalse($promotion->isAvailableForClient($outsider->id));
        $this->assertFalse($promotion->isAvailableForClient(null), 'гость в сегмент не попадает');
    }

    public function test_a_promotion_without_segments_is_open_to_everyone_and_a_disabled_segment_closes_it(): void
    {
        $member = $this->client();

        $open = $this->promotion();
        $this->assertTrue($open->isAvailableForClient($member->id));
        $this->assertTrue($open->isAvailableForClient(null));

        $closed = $this->promotion([$this->segment([$member], false)]);
        $this->assertFalse($closed->isAvailableForClient($member->id));
    }

    public function test_public_promotion_list_hides_a_segment_promotion_from_outsiders_and_guests(): void
    {
        $member = $this->client();
        $outsider = $this->client();
        $promotion = $this->promotion([$this->segment([$member])]);
        $names = fn () => collect($this->getJson('/api/public/promotions')->assertOk()->json('data'))->pluck('id')->all();

        Sanctum::actingAs($member);
        $this->assertContains($promotion->id, $names());

        Sanctum::actingAs($outsider);
        $this->assertNotContains($promotion->id, $names());

        $this->app['auth']->forgetGuards();
        $this->assertNotContains($promotion->id, $names());
    }
}
