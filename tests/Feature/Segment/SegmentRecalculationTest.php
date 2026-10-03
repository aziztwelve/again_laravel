<?php

namespace Tests\Feature\Segment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Segments\Segment;
use App\Services\Segment\SegmentRecalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SegmentRecalculationTest extends TestCase
{
    use DatabaseTransactions;

    private SegmentRecalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SegmentRecalculationService::class);
    }

    private function makeSegment(array $conditions, string $frequency = 'on_view'): Segment
    {
        return Segment::create([
            'name' => 'Test ' . uniqid(),
            'is_active' => true,
            'recalculate_frequency' => $frequency,
            'conditions' => $conditions,
        ]);
    }

    private function makePaidDeliveredOrder(Client $client, ?string $createdAt = null): Order
    {
        return Order::factory()->create([
            'client_id' => $client->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total_amount' => 1000,
            'created_at' => $createdAt ?? now()->subDays(10),
        ]);
    }

    /**
     * Сегмент «2 покупки» видит клиентов с двумя оплаченными доставленными
     * заказами, даже если клиент не верифицирован (verified_at = null).
     * Раньше фильтр whereNotNull('verified_at') отсекал почти всю базу.
     */
    public function test_unverified_client_with_two_purchases_matches_two_purchases_segment(): void
    {
        $client = Client::factory()->create(['verified_at' => null]);
        $this->makePaidDeliveredOrder($client);
        $this->makePaidDeliveredOrder($client);

        $segment = $this->makeSegment([
            'period' => 'last_year',
            'min_orders_count' => 2,
            'max_orders_count' => 2,
        ]);

        $this->service->recalculate($segment);

        $this->assertTrue(
            $segment->clients()->where('clients.id', $client->id)->exists(),
            'Неверифицированный клиент с 2 покупками должен попадать в сегмент «2 покупки»'
        );
    }

    /**
     * Сегмент «Ни одной покупки» (max_orders_count = 0) не должен захватывать
     * покупателей: считаются только доставленные оплаченные заказы.
     */
    public function test_zero_purchases_segment_excludes_buyers(): void
    {
        $buyer = Client::factory()->create();
        $this->makePaidDeliveredOrder($buyer);
        $this->makePaidDeliveredOrder($buyer);

        // Заказы не в статусе «доставлен + оплачен» покупкой не считаются
        $cancelledOnly = Client::factory()->create();
        Order::factory()->create([
            'client_id' => $cancelledOnly->id,
            'status' => OrderStatus::CANCELLED,
            'payment_status' => PaymentStatus::PENDING,
        ]);

        $noPurchases = Client::factory()->create();

        $segment = $this->makeSegment([
            'period' => 'all_time',
            'max_orders_count' => 0,
        ]);

        $this->service->recalculate($segment);

        $this->assertFalse(
            $segment->clients()->where('clients.id', $buyer->id)->exists(),
            'Клиент с 2 покупками не должен быть в сегменте «Ни одной покупки»'
        );
        $this->assertTrue(
            $segment->clients()->where('clients.id', $noPurchases->id)->exists(),
            'Клиент без покупок должен быть в сегменте «Ни одной покупки»'
        );
        $this->assertTrue(
            $segment->clients()->where('clients.id', $cancelledOnly->id)->exists(),
            'Отменённый неоплаченный заказ — не покупка'
        );
    }

    /**
     * Полная синхронизация убирает устаревшее членство: клиент с 2 покупками
     * не может одновременно состоять в «Ни одной покупки» и «2 покупки».
     */
    public function test_recalculate_all_removes_stale_membership_overlap(): void
    {
        $client = Client::factory()->create();
        $this->makePaidDeliveredOrder($client);
        $this->makePaidDeliveredOrder($client);

        $noPurchases = $this->makeSegment([
            'period' => 'all_time',
            'max_orders_count' => 0,
        ]);
        $twoPurchases = $this->makeSegment([
            'period' => 'last_year',
            'min_orders_count' => 2,
            'max_orders_count' => 2,
        ]);

        // Имитируем устаревшее состояние: клиент был в «Ни одной покупки»
        // до того, как совершил покупки.
        $noPurchases->clients()->attach($client->id, ['added_at' => now()]);

        $this->service->recalculateAll(onlyAutoRecalculable: false);

        $this->assertFalse(
            $noPurchases->clients()->where('clients.id', $client->id)->exists(),
            'После синхронизации клиент с покупками должен покинуть «Ни одной покупки»'
        );
        $this->assertTrue(
            $twoPurchases->clients()->where('clients.id', $client->id)->exists(),
            'После синхронизации клиент с 2 покупками должен быть в «2 покупки»'
        );
    }

    /**
     * Ручная синхронизация (кнопка «Синхронизировать») пересчитывает
     * в том числе сегменты с частотой manual.
     */
    public function test_manual_sync_covers_manual_frequency_segments(): void
    {
        $client = Client::factory()->create();
        $this->makePaidDeliveredOrder($client);
        $this->makePaidDeliveredOrder($client);

        $segment = $this->makeSegment([
            'period' => 'all_time',
            'min_orders_count' => 2,
        ], frequency: 'manual');

        $this->service->recalculateAll(onlyAutoRecalculable: false);

        $this->assertTrue(
            $segment->clients()->where('clients.id', $client->id)->exists(),
            'Сегмент с частотой manual должен пересчитываться при ручной синхронизации'
        );

        // А штатный авто-пересчёт manual-сегменты не трогает
        $segment->clients()->detach($client->id);
        $this->service->recalculateAll(onlyAutoRecalculable: true);
        $this->assertFalse(
            $segment->clients()->where('clients.id', $client->id)->exists(),
            'Авто-пересчёт не должен трогать manual-сегменты'
        );
    }
}
