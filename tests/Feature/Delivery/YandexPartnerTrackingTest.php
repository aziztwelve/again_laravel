<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class YandexPartnerTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeYandex(array $info = [], ?array $create = null): void
    {
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        $fake = [
            'https://yandex.test/api/b2b/platform/request/info*' => Http::response(array_merge([
                'request_id' => 'claim-1-udp',
                'state' => ['status' => 'CREATED'],
                'sharing_url' => 'https://dostavka.yandex.ru/route/x',
                'courier_order_id' => '1077128001',
            ], $info)),
        ];
        if ($create !== null) {
            $fake['https://yandex.test/api/b2b/platform/offers/confirm*'] = Http::response($create);
        }
        Http::fake($fake);
    }

    private function orderWithClaim(?string $tracking, string $claim = 'claim-1-udp'): array
    {
        $order = Order::factory()->create([
            'tracking_number' => $tracking,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'offer_id' => 'offer-1'],
            'moysklad_order_uuid' => null,
        ]);
        $yandexOrder = YandexOrder::create([
            'order_id' => $order->id, 'claim_id' => $claim, 'status' => 'CREATED', 'internal_status' => 'created',
            'delivery_type' => 'pickup', 'request_id' => (string) Str::uuid(),
        ]);

        return [$order, $yandexOrder];
    }

    public function test_poll_replaces_claim_id_in_track_with_partner_id(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $order->fresh()->delivery_data['yandex_tracking_number']);
        $this->assertSame('claim-1-udp', $yandexOrder->fresh()->claim_id);
        $this->assertSame('claim-1-udp', $order->fresh()->delivery_data['yandex_claim_id']);
    }

    public function test_blank_track_gets_partner_id(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim(null);

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
    }

    public function test_manually_entered_track_is_not_overwritten(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('MY-OWN-TRACK');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('MY-OWN-TRACK', $order->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
    }

    public function test_repeated_poll_changes_nothing(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');
        $service = app(YandexDeliveryService::class);

        $service->sync($yandexOrder);
        $updatedAt = $order->fresh()->updated_at;
        $service->sync($yandexOrder->fresh());

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        $this->assertEquals($updatedAt, $order->fresh()->updated_at);
    }

    public function test_without_partner_id_blank_track_falls_back_to_claim_id_and_partner_id_is_not_overwritten_later(): void
    {
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        $base = ['request_id' => 'claim-1-udp', 'state' => ['status' => 'CREATED']];
        Http::fake(['https://yandex.test/api/b2b/platform/request/info*' => Http::sequence()
            ->push($base + ['courier_order_id' => null])
            ->push($base + ['courier_order_id' => '555'])
            ->push($base + ['courier_order_id' => null])]);
        [$order, $yandexOrder] = $this->orderWithClaim(null);
        $service = app(YandexDeliveryService::class);

        $service->sync($yandexOrder);
        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number);

        $service->sync($yandexOrder->fresh());
        $this->assertSame('LO-555', $order->fresh()->tracking_number);

        $service->sync($yandexOrder->fresh());
        $this->assertSame('LO-555', $order->fresh()->tracking_number, 'номер партнёра не должен откатываться на ID заявки');
    }

    public function test_new_request_gets_partner_id_right_after_creation(): void
    {
        $this->fakeYandex(['request_id' => 'new-claim-udp', 'courier_order_id' => '1099000111'], ['request_id' => 'new-claim-udp']);
        $order = Order::factory()->create([
            'tracking_number' => null,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'offer_id' => 'offer-1'],
            'moysklad_order_uuid' => null,
        ]);

        app(YandexDeliveryService::class)->confirmOfferForOrder($order, new YandexOrder);

        $this->assertSame('LO-1099000111', $order->fresh()->tracking_number, 'ID заявки не должен попадать в трек');
        $this->assertSame('new-claim-udp', $order->fresh()->yandexOrder->claim_id);
        $this->assertSame('https://dostavka.yandex.ru/route/x', $order->fresh()->yandexOrder->tracking_url);
    }

    public function test_backfill_dry_run_writes_nothing_and_write_updates(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        Artisan::call('yandex:backfill-partner-tracking', ['--order' => $order->order_number]);
        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number, 'пробный прогон не должен писать');
        $this->assertNull($yandexOrder->fresh()->tracking_number);

        Artisan::call('yandex:backfill-partner-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);
        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
    }

    public function test_backfill_skips_manual_track(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('LO-MANUAL');

        Artisan::call('yandex:backfill-partner-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);

        $this->assertSame('LO-MANUAL', $order->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
    }

    public function test_backfill_sends_only_tracking_attribute_to_moysklad_and_does_not_queue_full_sync(): void
    {
        Queue::fake();
        Cache::forget('moysklad:customerorder:attributes');
        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'ms-token', 'enabled' => true]);
        $this->fakeYandex();
        Http::fake([
            'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/attributes' => Http::response(['rows' => [
                ['name' => 'Трек номер', 'meta' => ['href' => 'https://ms.test/attr/track', 'type' => 'attributemetadata']],
            ]]),
            'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/uuid-pilot' => Http::response([]),
        ]);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');
        $order->forceFill(['moysklad_order_uuid' => 'uuid-pilot'])->saveQuietly();

        Artisan::call('yandex:backfill-partner-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        Queue::assertNothingPushed();
        Http::assertSent(function (Request $r) {
            return $r->method() === 'PUT'
                && str_ends_with($r->url(), '/entity/customerorder/uuid-pilot')
                && $r->data() === ['attributes' => [['meta' => ['href' => 'https://ms.test/attr/track', 'type' => 'attributemetadata'], 'value' => 'LO-1077128001']]];
        });
    }

    public function test_regular_poll_does_not_queue_full_moysklad_sync_and_pushes_only_tracking_attribute(): void
    {
        Queue::fake();
        Cache::forget('moysklad:customerorder:attributes');
        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'ms-token', 'enabled' => true]);
        $this->fakeYandex();
        Http::fake([
            'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/attributes' => Http::response(['rows' => [
                ['name' => 'Трек номер', 'meta' => ['href' => 'https://ms.test/attr/track', 'type' => 'attributemetadata']],
            ]]),
            'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/uuid-poll' => Http::response([]),
        ]);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');
        $order->forceFill(['moysklad_order_uuid' => 'uuid-poll'])->saveQuietly();

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        Queue::assertNothingPushed();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/entity/customerorder/uuid-poll')
            && array_keys($r->data()) === ['attributes']
            && $r->data()['attributes'][0]['value'] === 'LO-1077128001');
    }
}
