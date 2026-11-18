<?php

namespace Tests\Feature\Delivery;

use App\Jobs\SyncYandexOrderJob;
use App\Models\DeliveryMethod;
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

class YandexPackageTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeYandex(array $info = [], ?array $create = null): void
    {
        // Остальные тесты проверяют сам номер грузоместа: ограничение по дате создания заказа снято.
        config(['services.yandex_delivery.package_tracking_from' => null]);
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
                // courier_order_id (номер партнёра LO-…) больше не трек: трек — номер грузоместа ниже.
                'courier_order_id' => '1077128001',
                'request' => ['places' => [['barcode' => 'YD6AED8E1D8C7557A3A']]],
            ], $info)),
        ];
        if ($create !== null) {
            $fake['https://yandex.test/api/b2b/platform/offers/confirm*'] = Http::response($create);
        }
        Http::fake($fake);
    }

    private function orderWithClaim(?string $tracking, string $claim = 'claim-1-udp', mixed $createdAt = null): array
    {
        $order = Order::factory()->create([
            'created_at' => $createdAt ?? now(),
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

    public function test_poll_replaces_claim_id_in_track_with_package_number(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $yandexOrder->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->delivery_data['yandex_tracking_number']);
        $this->assertSame('claim-1-udp', $yandexOrder->fresh()->claim_id);
        $this->assertSame('claim-1-udp', $order->fresh()->delivery_data['yandex_claim_id']);
    }

    public function test_blank_track_gets_package_number(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim(null);

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
    }

    public function test_manually_entered_track_is_not_overwritten(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('MY-OWN-TRACK');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('MY-OWN-TRACK', $order->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $yandexOrder->fresh()->tracking_number);
    }

    public function test_repeated_poll_changes_nothing(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');
        $service = app(YandexDeliveryService::class);

        $service->sync($yandexOrder);
        $updatedAt = $order->fresh()->updated_at;
        $service->sync($yandexOrder->fresh());

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        $this->assertEquals($updatedAt, $order->fresh()->updated_at);
    }

    public function test_without_package_number_blank_track_falls_back_to_claim_id_and_package_number_is_not_overwritten_later(): void
    {
        config(['services.yandex_delivery.package_tracking_from' => null]);
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        $base = ['request_id' => 'claim-1-udp', 'state' => ['status' => 'CREATED']];
        Http::fake(['https://yandex.test/api/b2b/platform/request/info*' => Http::sequence()
            ->push($base)
            ->push($base + ['request' => ['places' => [['barcode' => 'YD555']]]])
            ->push($base)]);
        [$order, $yandexOrder] = $this->orderWithClaim(null);
        $service = app(YandexDeliveryService::class);

        $service->sync($yandexOrder);
        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number);

        $service->sync($yandexOrder->fresh());
        $this->assertSame('YD555', $order->fresh()->tracking_number);

        $service->sync($yandexOrder->fresh());
        $this->assertSame('YD555', $order->fresh()->tracking_number, 'номер грузоместа не должен откатываться на ID заявки');
    }

    public function test_delayed_sync_job_sets_package_number_when_yandex_has_assigned_it(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        $job = (new SyncYandexOrderJob($yandexOrder->id))->withFakeQueueInteractions();
        $job->handle(app(YandexDeliveryService::class));

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        $job->assertNotReleased();
    }

    public function test_delayed_sync_job_retries_while_package_number_is_not_assigned_yet(): void
    {
        $this->fakeYandex(['courier_order_id' => null, 'request' => ['places' => []]]);
        [$order, $yandexOrder] = $this->orderWithClaim(null);

        $job = (new SyncYandexOrderJob($yandexOrder->id))->withFakeQueueInteractions();
        $job->handle(app(YandexDeliveryService::class));

        $job->assertReleased(delay: 90);
    }

    public function test_creating_request_schedules_delayed_package_number_check_when_number_is_missing(): void
    {
        Queue::fake();
        $this->fakeYandex(['request_id' => 'new-claim-udp', 'courier_order_id' => null, 'request' => ['places' => []]], ['request_id' => 'new-claim-udp']);
        $method = DeliveryMethod::firstOrCreate(['code' => 'yandex_pickup'], ['name' => 'ya-'.Str::uuid(), 'is_active' => true]);
        $order = Order::factory()->create([
            'payment_status' => 'paid',
            'delivery_method_id' => $method->id,
            'tracking_number' => null,
            'delivery_data' => ['provider' => 'yandex', 'delivery_type' => 'pickup', 'offer_id' => 'offer-1'],
            'moysklad_order_uuid' => null,
        ]);

        (new \App\Jobs\CreateYandexOrderJob($order->id))->handle(app(YandexDeliveryService::class));

        Queue::assertPushed(SyncYandexOrderJob::class, fn ($job) => $job->yandexOrderId === $order->fresh()->yandexOrder->id && $job->delay !== null);
    }

    public function test_old_partner_id_track_is_replaced_by_package_number(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('LO-1077128001');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
    }

    public function test_partner_id_alone_is_no_longer_a_track(): void
    {
        $this->fakeYandex(['request' => ['places' => []]]);
        [$order, $yandexOrder] = $this->orderWithClaim(null);

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number, 'LO-… больше не ставится');
    }

    public function test_our_own_place_barcode_is_not_used_as_track(): void
    {
        $this->fakeYandex(['request' => ['places' => [['barcode' => 'place-abc123']]]]);
        [$order, $yandexOrder] = $this->orderWithClaim(null);

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number);
    }

    public function test_with_several_places_only_the_first_is_the_track(): void
    {
        $this->fakeYandex(['request' => ['places' => [['barcode' => 'YD-FIRST'], ['barcode' => 'YD-SECOND']]]]);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD-FIRST', $order->fresh()->tracking_number);
        $this->assertSame('YD-FIRST', $yandexOrder->fresh()->tracking_number);
    }

    public function test_package_number_helper_reads_only_a_real_first_barcode(): void
    {
        $this->assertSame('YD1', YandexDeliveryService::packageTrackingNumber(['request' => ['places' => [['barcode' => ' YD1 ']]]]));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber(['request' => ['places' => []]]));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber(['request' => ['places' => [['barcode' => '']]]]));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber(['request' => ['places' => [['barcode' => 'place-1']]]]));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber(['request' => ['places' => 'junk']]));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber(null));
        $this->assertNull(YandexDeliveryService::packageTrackingNumber([]));
    }

    public function test_new_order_gets_package_number_when_cutoff_is_set(): void
    {
        $this->fakeYandex();
        config(['services.yandex_delivery.package_tracking_from' => '2026-11-18 15:20:00']);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp', 'claim-1-udp', '2026-11-18 15:30:00');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
    }

    public function test_old_order_keeps_the_partner_id_scheme_when_cutoff_is_set(): void
    {
        $this->fakeYandex();
        config(['services.yandex_delivery.package_tracking_from' => '2026-11-18 15:20:00']);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp', 'claim-1-udp', '2026-11-18 15:10:00');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number, 'старый заказ остаётся на номере партнёра');
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $order->fresh()->delivery_data['yandex_tracking_number']);
    }

    public function test_poll_does_not_replace_partner_id_of_an_old_order_and_does_not_touch_moysklad(): void
    {
        Queue::fake();
        Cache::forget('moysklad:customerorder:attributes');
        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'ms-token', 'enabled' => true]);
        $this->fakeYandex();
        config(['services.yandex_delivery.package_tracking_from' => '2026-11-18 15:20:00']);
        Http::fake(['https://api.moysklad.ru/*' => Http::response([])]);
        [$order, $yandexOrder] = $this->orderWithClaim('LO-1077128001', 'claim-old-udp', '2026-11-10 10:00:00');
        $order->forceFill(['moysklad_order_uuid' => 'uuid-old'])->saveQuietly();
        $yandexOrder->update(['tracking_number' => 'LO-1077128001']);

        app(YandexDeliveryService::class)->sync($yandexOrder->fresh());
        app(YandexDeliveryService::class)->sync($yandexOrder->fresh());

        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
        $this->assertSame('LO-1077128001', $yandexOrder->fresh()->tracking_number);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.moysklad.ru'));
    }

    public function test_order_created_exactly_at_the_cutoff_is_new(): void
    {
        $this->fakeYandex();
        config(['services.yandex_delivery.package_tracking_from' => '2026-11-18 15:20:00']);
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp', 'claim-edge-udp', '2026-11-18 15:20:00');

        app(YandexDeliveryService::class)->sync($yandexOrder);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
    }

    public function test_default_cutoff_is_set_so_that_existing_orders_are_not_switched_by_a_deploy(): void
    {
        $this->assertNotEmpty(config('services.yandex_delivery.package_tracking_from'));
    }

    public function test_backfill_write_is_refused_while_the_cutoff_is_set_but_dry_run_works(): void
    {
        $this->fakeYandex();
        config(['services.yandex_delivery.package_tracking_from' => '2026-11-18 15:20:00']);
        [$order, $yandexOrder] = $this->orderWithClaim('LO-1077128001', 'claim-old-udp', '2026-11-10 10:00:00');

        $this->assertSame(1, Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]));
        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);

        $this->assertSame(0, Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number]));
        $this->assertSame('LO-1077128001', $order->fresh()->tracking_number);
    }

    public function test_backfill_replaces_partner_id_track_and_uses_saved_response_before_asking_yandex(): void
    {
        config(['services.yandex_delivery.package_tracking_from' => null]);
        Http::fake(); // любой запрос к Яндексу в этом тесте — ошибка
        [$order, $yandexOrder] = $this->orderWithClaim('LO-1077128001');
        \App\Models\YandexApiLog::create([
            'order_id' => $order->id, 'claim_id' => 'claim-1-udp', 'direction' => 'outbound',
            'method' => '/api/b2b/platform/request/info', 'http_method' => 'GET', 'url' => 'https://yandex.test/x',
            'status_code' => 200, 'is_error' => false,
            'response_body' => ['courier_order_id' => 1077128001, 'request' => ['places' => [['barcode' => 'YD6AED8E1D8C7557A3A']]]],
        ]);

        Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0, '--no-fetch' => true]);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $yandexOrder->fresh()->tracking_number);
        Http::assertNothingSent();
    }

    public function test_backfill_dry_run_writes_nothing_and_write_updates(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('claim-1-udp');

        Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number]);
        $this->assertSame('claim-1-udp', $order->fresh()->tracking_number, 'пробный прогон не должен писать');
        $this->assertNull($yandexOrder->fresh()->tracking_number);

        Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);
        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $yandexOrder->fresh()->tracking_number);
    }

    public function test_backfill_skips_manual_track(): void
    {
        $this->fakeYandex();
        [$order, $yandexOrder] = $this->orderWithClaim('MANUAL-TRACK');

        Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);

        $this->assertSame('MANUAL-TRACK', $order->fresh()->tracking_number);
        $this->assertSame('YD6AED8E1D8C7557A3A', $yandexOrder->fresh()->tracking_number);
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

        Artisan::call('yandex:backfill-package-tracking', ['--order' => $order->order_number, '--write' => true, '--sleep' => 0]);

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        Queue::assertNothingPushed();
        Http::assertSent(function (Request $r) {
            return $r->method() === 'PUT'
                && str_ends_with($r->url(), '/entity/customerorder/uuid-pilot')
                && $r->data() === ['attributes' => [['meta' => ['href' => 'https://ms.test/attr/track', 'type' => 'attributemetadata'], 'value' => 'YD6AED8E1D8C7557A3A']]];
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

        $this->assertSame('YD6AED8E1D8C7557A3A', $order->fresh()->tracking_number);
        Queue::assertNothingPushed();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/entity/customerorder/uuid-poll')
            && array_keys($r->data()) === ['attributes']
            && $r->data()['attributes'][0]['value'] === 'YD6AED8E1D8C7557A3A');
    }
}
