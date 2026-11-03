<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryMethod;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Services\MoySklad\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class MoySkladDeliveryAttributesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'test-token']);

        // Метаданные полей берём из кэша, чтобы тест не ходил в МойСклад.
        Cache::put('moysklad:customerorder:attributes', [
            'Служба доставки' => ['href' => 'meta-service'],
            'Трек номер' => ['href' => 'meta-track'],
            'Трек номер ПР' => ['href' => 'meta-post'],
            'Чек не пробивать' => ['href' => 'meta-receipt'],
        ], 600);
    }

    private function method(string $code, string $name): DeliveryMethod
    {
        return DeliveryMethod::create(['name' => $name, 'code' => $code.'-'.Str::random(6), 'is_active' => true]);
    }

    /** Значения полей заказа, которые мы бы отправили в МойСклад, по href мета-данных. */
    private function attributes(Order $order): array
    {
        $method = new ReflectionMethod(OrderService::class, 'buildCustomAttributes');
        $method->setAccessible(true);

        $result = [];
        foreach ($method->invoke(new OrderService(), $order->fresh()) as $attribute) {
            $result[$attribute['meta']['href']] = $attribute['value'];
        }

        return $result;
    }

    public function test_delivery_service_contains_payment_and_delivery_method_lines(): void
    {
        $method = $this->method('cdek_pickup', 'Пункт выдачи СДЭК');
        $order = Order::factory()->create(['payment_method' => 'card_ru', 'delivery_method_id' => $method->id]);

        $this->assertSame(
            "Оплата картами РФ, TPay, СБП\nПункт выдачи СДЭК",
            $this->attributes($order)['meta-service'],
        );
    }

    public function test_tracking_number_uses_order_field_then_cdek_number_from_legacy_meta(): void
    {
        $own = Order::factory()->create(['tracking_number' => 'YA689C2EC71F2C84F59']);
        $this->assertSame('YA689C2EC71F2C84F59', $this->attributes($own)['meta-track']);

        $legacy = Order::factory()->create([
            'tracking_number' => null,
            'legacy_meta' => ['cdek_track_number' => '10337704825'],
        ]);
        $this->assertSame('10337704825', $this->attributes($legacy)['meta-track']);
    }

    public function test_post_track_for_post_methods_reuses_the_general_track(): void
    {
        $post = $this->method('russian_post_courier', 'Доставка курьером Почты России');
        $order = Order::factory()->create(['tracking_number' => 'EF537485028RU', 'delivery_method_id' => $post->id]);

        $this->assertSame('EF537485028RU', $this->attributes($order)['meta-post']);
    }

    public function test_post_track_is_not_sent_for_other_services(): void
    {
        $cdek = $this->method('cdek_pickup', 'Пункт выдачи СДЭК');
        $order = Order::factory()->create(['tracking_number' => '10337704825', 'delivery_method_id' => $cdek->id]);

        $this->assertArrayNotHasKey('meta-post', $this->attributes($order));
    }

    public function test_empty_values_are_not_sent(): void
    {
        $order = Order::factory()->create([
            'tracking_number' => null,
            'payment_method' => null,
            'delivery_method_id' => null,
            'legacy_meta' => null,
        ]);

        $attributes = $this->attributes($order);

        $this->assertArrayNotHasKey('meta-service', $attributes);
        $this->assertArrayNotHasKey('meta-track', $attributes);
        $this->assertArrayNotHasKey('meta-post', $attributes);
    }

    public function test_new_tracking_number_resyncs_an_already_exported_order(): void
    {
        Bus::fake([SyncOrderToMoySkladJob::class]);

        $exported = Order::factory()->create(['moysklad_order_uuid' => (string) Str::uuid(), 'tracking_number' => null]);
        $exported->update(['tracking_number' => 'EF537485028RU']);
        Bus::assertDispatched(SyncOrderToMoySkladJob::class, fn ($job) => $job->orderId === $exported->id);

        $exported->update(['seller_comment' => 'без смены трека']);
        Bus::assertDispatchedTimes(SyncOrderToMoySkladJob::class, 1);
    }

    public function test_tracking_number_does_not_resync_an_order_that_was_never_exported(): void
    {
        Bus::fake([SyncOrderToMoySkladJob::class]);

        $order = Order::factory()->create(['moysklad_order_uuid' => null, 'tracking_number' => null]);
        $order->update(['tracking_number' => 'EF537485028RU']);

        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }
}
