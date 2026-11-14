<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromoCodeListSortTest extends TestCase
{
    use DatabaseTransactions;

    private function makeCodes(): void
    {
        foreach (['zzsort_Beta', 'zzsort_alpha', 'zzsort_Gamma'] as $code) {
            PromoCode::create([
                'code' => $code, 'discount_amount' => 10, 'discount_type' => 'percentage',
                'is_active' => true, 'type' => 'all', 'applies_to_all_products' => true, 'applies_to_all_clients' => true,
            ]);
        }
    }

    private function codes(array $query): array
    {
        $response = $this->getJson('/api/promo-codes?'.http_build_query($query + ['code' => 'zzsort_', 'per_page' => 50]))->assertOk();

        return collect($response->json('data'))->pluck('code')->all();
    }

    public function test_list_can_be_sorted_by_name_both_ways(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeCodes();

        $this->assertSame(['zzsort_alpha', 'zzsort_Beta', 'zzsort_Gamma'], $this->codes(['sort_by' => 'code', 'sort_order' => 'asc']));
        $this->assertSame(['zzsort_Gamma', 'zzsort_Beta', 'zzsort_alpha'], $this->codes(['sort_by' => 'code', 'sort_order' => 'desc']));
    }

    public function test_unknown_sort_field_is_ignored_and_default_order_stays(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeCodes();

        $this->assertCount(3, $this->codes(['sort_by' => 'discount_amount; drop table promo_codes', 'sort_order' => 'asc']));
    }
}
