<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Models\Promotion;
use App\Models\Segments\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Колонка «Сегмент» в списках промокодов и акций в админке: списки отдают сегменты. */
class PromoSegmentColumnTest extends TestCase
{
    use DatabaseTransactions;

    private function segment(string $name, bool $active = true): Segment
    {
        return Segment::create(['name' => $name, 'is_active' => $active, 'recalculate_frequency' => 'manual']);
    }

    private function promo(string $code): PromoCode
    {
        return PromoCode::create([
            'code' => $code, 'discount_amount' => 10, 'discount_type' => 'percentage',
            'is_active' => true, 'type' => 'all', 'applies_to_all_products' => true, 'applies_to_all_clients' => true,
        ]);
    }

    private function listedPromos(): \Illuminate\Support\Collection
    {
        $response = $this->getJson('/api/promo-codes?code=zzseg_&per_page=50')->assertOk();

        return collect($response->json('data'))->keyBy('code');
    }

    public function test_promo_code_list_returns_the_segments_of_each_code(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $vip = $this->segment('zzseg VIP');
        $inactive = $this->segment('zzseg Выключенный', false);
        $this->promo('zzseg_none');
        $this->promo('zzseg_one')->segments()->attach($vip->id, ['auto_apply' => true]);
        $this->promo('zzseg_two')->segments()->attach([$vip->id => ['auto_apply' => false], $inactive->id => ['auto_apply' => true]]);

        $list = $this->listedPromos();

        $this->assertSame([], $list['zzseg_none']['segments']);
        $this->assertSame(['zzseg VIP'], collect($list['zzseg_one']['segments'])->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['zzseg VIP', 'zzseg Выключенный'], collect($list['zzseg_two']['segments'])->pluck('name')->all());
        $disabled = collect($list['zzseg_two']['segments'])->firstWhere('name', 'zzseg Выключенный');
        $this->assertFalse($disabled['is_active']);
        $this->assertSame($inactive->id, $disabled['id']);
    }

    public function test_promo_code_list_does_not_query_segments_per_row(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $segment = $this->segment('zzseg N+1');
        foreach (range(1, 6) as $i) {
            $this->promo("zzseg_n{$i}")->segments()->attach($segment->id, ['auto_apply' => true]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->listedPromos();
        $segmentQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'promo_code_segment'))->count();

        $this->assertSame(1, $segmentQueries, 'сегменты должны подгружаться одним запросом на весь список');
    }

    public function test_promotion_list_returns_the_segments_of_each_promotion(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $segment = $this->segment('zzseg Акции');
        $linked = Promotion::create(['name' => 'zzseg linked', 'min_purchase_amount' => 0, 'allow_promo_codes' => true, 'is_active' => true, 'is_stackable' => true, 'priority' => 0]);
        $public = Promotion::create(['name' => 'zzseg public', 'min_purchase_amount' => 0, 'allow_promo_codes' => true, 'is_active' => true, 'is_stackable' => true, 'priority' => 0]);
        $linked->segments()->attach($segment->id);

        $rows = collect($this->getJson('/api/promotions?search=zzseg&per_page=50')->assertOk()->json('data.data'))->keyBy('name');

        $this->assertSame(['zzseg Акции'], collect($rows['zzseg linked']['segments'])->pluck('name')->all());
        $this->assertSame([], $rows['zzseg public']['segments']);
    }
}
