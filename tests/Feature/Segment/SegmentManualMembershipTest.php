<?php

namespace Tests\Feature\Segment;

use App\Actions\Segment\AttachClientsToSegmentAction;
use App\Actions\Segment\DetachClientsFromSegmentAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Promotion;
use App\Models\Segments\Segment;
use App\Models\User;
use App\Services\Segment\SegmentRecalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SegmentManualMembershipTest extends TestCase
{
    use DatabaseTransactions;

    private function segment(?array $conditions = ['min_total_amount' => 30000], string $frequency = 'on_view'): Segment
    {
        return Segment::create(['name'=>'Manual test '.uniqid(), 'conditions'=>$conditions,
            'is_active'=>true, 'recalculate_frequency'=>$frequency]);
    }

    private function buy(Client $client, int $total = 40000): Order
    {
        return Order::factory()->create(['client_id'=>$client->id,'total_amount'=>$total,
            'status'=>OrderStatus::DELIVERED,'payment_status'=>PaymentStatus::PAID]);
    }

    private function pin(Segment $segment, Client $client): void
    {
        app(AttachClientsToSegmentAction::class)->execute($segment, [$client->id]);
    }

    private function unpin(Segment $segment, Client $client): array
    {
        return app(DetachClientsFromSegmentAction::class)->execute($segment, [$client->id]);
    }

    private function recalc(Segment $segment): void
    {
        app(SegmentRecalculationService::class)->recalculate($segment);
    }

    public static function membershipCases(): array
    {
        $cases=[];
        foreach (['on_view','manual'] as $frequency) {
            foreach ([null,['min_total_amount'=>30000]] as $conditions) {
                foreach ([0,10000,40000] as $total) {
                    $cases[] = [$frequency,$conditions,$total];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('membershipCases')]
    public function test_pin_survives_repeated_recalculation_and_unpin_respects_conditions(string $frequency, ?array $conditions, int $total): void
    {
        $segment=$this->segment($conditions,$frequency);
        $client=Client::factory()->create(['verified_at'=>null]);
        if($total) $this->buy($client,$total);
        $this->pin($segment,$client);
        $this->recalc($segment);
        $this->recalc($segment);
        $member=$segment->clients()->find($client->id);
        $this->assertNotNull($member);
        $this->assertTrue((bool)$member->pivot->is_manual);
        $removed=$this->unpin($segment,$client);
        $this->recalc($segment);
        $expected=$conditions !== null && $total>=30000;
        $member=$segment->clients()->find($client->id);
        $this->assertSame($expected,$member!==null);
        $this->assertSame($expected?[]:[$client->id],$removed);
        if($member) $this->assertFalse((bool)$member->pivot->is_manual);
    }

    public function test_pin_existing_automatic_client_preserves_date_and_does_not_duplicate(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$order=$this->buy($client);
        $this->recalc($segment);
        $before=$segment->clients()->find($client->id)->pivot->added_at;
        $this->travel(10)->minutes();
        $this->pin($segment,$client);$this->pin($segment,$client);
        $this->assertSame(1,$segment->clients()->whereKey($client->id)->count());
        $this->assertSame($before,$segment->clients()->find($client->id)->pivot->added_at);
        $order->update(['status'=>OrderStatus::CANCELLED]);
        $this->recalc($segment);
        $this->assertNotNull($segment->clients()->find($client->id));
        $this->unpin($segment,$client);
        $this->assertNull($segment->clients()->find($client->id));
    }

    public function test_unpinned_automatic_member_is_removed_when_orders_stop_matching(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$order=$this->buy($client);
        $this->recalc($segment);
        $this->assertFalse((bool)$segment->clients()->find($client->id)->pivot->is_manual);
        $order->update(['status'=>OrderStatus::CANCELLED]);$this->recalc($segment);
        $this->assertNull($segment->clients()->find($client->id));
    }

    public function test_mass_recalculation_and_changed_conditions_keep_pins(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$this->pin($segment,$client);
        $segment->update(['conditions'=>['min_orders_count'=>100,'period'=>'last_year']]);
        app(SegmentRecalculationService::class)->recalculateAll(onlyAutoRecalculable:false);
        $this->assertNotNull($segment->clients()->find($client->id));
    }

    public function test_promo_and_promotion_access_follow_pin_and_unpin(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();
        $code=PromoCode::create(['code'=>'manual-'.uniqid(),'discount_amount'=>10,'discount_type'=>'percentage','is_active'=>true,'applies_to_all_clients'=>false]);
        $segment->promoCodes()->attach($code->id,['auto_apply'=>true]);
        $promotion=Promotion::create(['name'=>'Manual promotion','min_purchase_amount'=>0,'is_active'=>true,'allow_promo_codes'=>true]);
        $segment->promotions()->attach($promotion->id);
        $this->pin($segment,$client);$this->recalc($segment);
        $this->assertTrue($client->promoCodes()->whereKey($code->id)->exists());
        $this->assertTrue($promotion->isAvailableForClient($client->id));
        $segment->update(['is_active'=>false]);
        $this->assertFalse($promotion->isAvailableForClient($client->id));
        $segment->update(['is_active'=>true]);
        $this->unpin($segment,$client);
        $this->assertFalse($client->promoCodes()->whereKey($code->id)->exists());
        $this->assertFalse($promotion->isAvailableForClient($client->id));
    }

    public function test_unpin_retains_coupon_when_client_still_matches(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$this->buy($client);
        $code=PromoCode::create(['code'=>'keep-'.uniqid(),'discount_amount'=>10,'discount_type'=>'percentage','is_active'=>true]);
        $segment->promoCodes()->attach($code->id);$this->pin($segment,$client);$this->unpin($segment,$client);
        $this->assertTrue($client->promoCodes()->whereKey($code->id)->exists());
    }

    public function test_removing_one_segment_does_not_revoke_coupon_granted_by_another(): void
    {
        $first=$this->segment();$second=$this->segment();$client=Client::factory()->create();
        $code=PromoCode::create(['code'=>'shared-'.uniqid(),'discount_amount'=>10,'discount_type'=>'percentage','is_active'=>true]);
        $first->promoCodes()->attach($code->id);$second->promoCodes()->attach($code->id);
        $this->pin($first,$client);$this->pin($second,$client);$this->unpin($first,$client);
        $this->assertTrue($client->promoCodes()->whereKey($code->id)->exists());
        $this->unpin($second,$client);
        $this->assertFalse($client->promoCodes()->whereKey($code->id)->exists());
    }

    public function test_api_pin_list_and_unpin_for_staff(): void
    {
        Sanctum::actingAs(User::factory()->create());$segment=$this->segment();$client=Client::factory()->create();
        $url='/api/segments/'.$segment->id.'/clients';
        $this->postJson($url,['client_ids'=>[$client->id]])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.is_manual',true);
        $this->deleteJson($url,['client_ids'=>[$client->id]])->assertOk()->assertJsonPath('data.removed_count',1);
        $this->deleteJson($url,['client_ids'=>[$client->id]])->assertOk()->assertJsonPath('data.removed_count',0);
    }

    public function test_api_unpin_matching_client_reports_zero_removed(): void
    {
        Sanctum::actingAs(User::factory()->create());$segment=$this->segment();$client=Client::factory()->create();$this->buy($client);$this->pin($segment,$client);
        $this->deleteJson('/api/segments/'.$segment->id.'/clients',['client_ids'=>[$client->id]])
            ->assertOk()->assertJsonPath('data.removed_count',0);
        $this->assertFalse((bool)$segment->clients()->find($client->id)->pivot->is_manual);
    }

    public function test_api_rejects_guest_customer_duplicates_nonexistent_and_deleted_clients(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$url='/api/segments/'.$segment->id.'/clients';
        $this->postJson($url,['client_ids'=>[$client->id]])->assertUnauthorized();
        Sanctum::actingAs($client);
        $this->postJson($url,['client_ids'=>[$client->id]])->assertForbidden();
        $this->deleteJson($url,['client_ids'=>[$client->id]])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        foreach([[],[$client->id,$client->id],[999999999]] as $ids) $this->postJson($url,['client_ids'=>$ids])->assertUnprocessable();
        $client->delete();$this->postJson($url,['client_ids'=>[$client->id]])->assertUnprocessable();
        $this->assertSame(0,$segment->clients()->count());
    }

    public function test_deleted_pinned_client_has_no_promotion_access(): void
    {
        $segment=$this->segment();$client=Client::factory()->create();$this->pin($segment,$client);
        $promotion=Promotion::create(['name'=>'Deleted client','min_purchase_amount'=>0,'is_active'=>true]);$segment->promotions()->attach($promotion->id);
        $client->delete();$this->recalc($segment);
        $this->assertFalse($promotion->isAvailableForClient($client->id));
        $this->assertSame(0,$segment->clients()->count());
    }
}
