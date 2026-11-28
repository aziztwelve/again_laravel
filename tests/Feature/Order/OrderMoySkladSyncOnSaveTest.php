<?php

namespace Tests\Feature\Order;

use App\Jobs\PushOrderTracksToMoySkladJob;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Админка при любом сохранении присылает заказ целиком. Полная выгрузка в МойСклад (она возвращает
 * туда статус сайта) должна идти, только когда реально изменилось то, что хранится в документе;
 * если менялись лишь треки — отправляются только они.
 */
class OrderMoySkladSyncOnSaveTest extends TestCase
{
    use DatabaseTransactions;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncOrderToMoySkladJob::class, PushOrderTracksToMoySkladJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $method = DeliveryMethod::where('code', 'cdek_pickup')->firstOrFail();
        $this->order = Order::create([
            'order_number' => 'MS-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending', 'total_amount' => 1400,
            'delivery_cost' => 400, 'delivery_method_id' => $method->id, 'delivery_date' => '2026-11-30 00:00:00',
            'moysklad_order_uuid' => 'ms-'.uniqid(), 'email' => 'ms@example.com',
        ]);
        $product = Product::create([
            'name' => 'MS product '.uniqid(), 'price' => 1000, 'currency' => 'RUB',
            'is_active' => true, 'has_variants' => false, 'stock_quantity' => 10,
        ]);
        $this->order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 1000]);
        $this->order->address()->create([
            'country' => 'Россия', 'city' => 'Москва', 'address' => 'Тверская ул, 7',
            'recipient_first_name' => 'Анна', 'recipient_last_name' => 'Тестова', 'recipient_phone' => '+79990000000',
        ]);
    }

    /** То, что админка шлёт при сохранении любого поля (useOrderInlineEdit.buildPayload). */
    private function payload(array $patch = []): array
    {
        $order = $this->order->fresh(['address', 'deliveryMethod']);
        $address = $order->address;

        return array_replace_recursive([
            'client_id' => null,
            'user' => ['first_name' => '', 'last_name' => '', 'phone' => ''],
            'recipient' => [
                'first_name' => $address->recipient_first_name, 'last_name' => $address->recipient_last_name,
                'middle_name' => $address->recipient_middle_name ?? '', 'phone' => $address->recipient_phone,
            ],
            'delivery_address' => [
                'country' => $address->country, 'region' => '', 'city' => $address->city, 'postal_code' => $address->postal_code ?? '',
                'address' => $address->address, 'entrance' => '', 'floor' => '', 'delivery_comment' => '', 'buyer_comment' => '',
                'delivery_date' => $order->delivery_date?->toJSON(),
            ],
            'delivery_date' => $order->delivery_date?->toJSON(),
            'source' => null,
            'delivery_method_id' => $order->delivery_method_id,
            'delivery_method' => ['name' => $order->deliveryMethod->name],
            'notes' => $order->notes,
            'seller_comment' => $order->seller_comment,
        ], $patch);
    }

    private function save(array $patch = []): void
    {
        $this->putJson('/api/orders/'.$this->order->id, $this->payload($patch))->assertOk();
    }

    private function assertNothingSent(): void
    {
        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
        Bus::assertNotDispatched(PushOrderTracksToMoySkladJob::class);
    }

    private function assertOnlyTracksSent(): void
    {
        Bus::assertDispatched(PushOrderTracksToMoySkladJob::class);
        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_saving_without_changes_sends_nothing(): void
    {
        $this->save();

        $this->assertNothingSent();
    }

    public function test_seller_comment_does_not_resync_the_document(): void
    {
        $this->save(['seller_comment' => 'позвонить']);

        $this->assertNothingSent();
    }

    public function test_post_track_sends_only_tracks(): void
    {
        $this->save(['custom_fields' => ['russian_post_tracking_number' => '80080012345678']]);

        $this->assertOnlyTracksSent();
    }

    public function test_general_track_sends_only_tracks(): void
    {
        $this->save(['custom_fields' => ['tracking_number' => '1234567890']]);

        $this->assertOnlyTracksSent();
    }

    public function test_cdek_track_field_sends_only_tracks(): void
    {
        $this->save(['custom_fields' => ['cdek_tracking_number' => '10337704825']]);

        $this->assertOnlyTracksSent();
    }

    public function test_status_change_resyncs_the_document(): void
    {
        $this->save(['status' => 'processing']);

        Bus::assertDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_address_change_resyncs_the_document(): void
    {
        $this->save(['delivery_address' => ['address' => 'Тверская ул, 9']]);

        Bus::assertDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_recipient_change_resyncs_the_document(): void
    {
        $this->save(['recipient' => ['middle_name' => 'Ивановна']]);

        Bus::assertDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_note_change_resyncs_the_document(): void
    {
        $this->save(['notes' => 'без звонка']);

        Bus::assertDispatched(SyncOrderToMoySkladJob::class);
    }
}
