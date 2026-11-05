<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DuplicateOrderMoySkladTest extends TestCase
{
    use DatabaseTransactions;

    public function test_copy_gets_own_moysklad_sync_fresh_reservation_and_no_foreign_ids(): void
    {
        Bus::fake([SyncOrderToMoySkladJob::class]);
        $this->travelTo(now()->startOfSecond());
        $original = Order::factory()->create([
            'status' => OrderStatus::CANCELLED,
            'moysklad_order_uuid' => 'orig-order',
            'moysklad_demand_uuid' => 'orig-demand',
            'moysklad_paymentin_uuid' => 'orig-pay',
            'reservation_expires_at' => now()->subDay(),
        ]);

        $response = app(OrderController::class)->duplicate($original)->getData(true);
        $copy = Order::findOrFail($response['order']['id']);

        $this->assertNull($copy->moysklad_order_uuid);
        $this->assertNull($copy->moysklad_demand_uuid);
        $this->assertNull($copy->moysklad_paymentin_uuid);
        $this->assertTrue($copy->reservation_expires_at->isFuture());
        Bus::assertDispatched(SyncOrderToMoySkladJob::class, fn ($job) => $job->orderId === $copy->id);
        $this->assertSame('orig-order', $original->fresh()->moysklad_order_uuid);
    }
}
