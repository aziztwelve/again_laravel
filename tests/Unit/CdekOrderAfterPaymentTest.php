<?php

namespace Tests\Unit;

use App\Events\OrderPaid;
use App\Jobs\CreateCdekOrderJob;
use App\Listeners\CreateCdekOrderAfterPayment;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class CdekOrderAfterPaymentTest extends TestCase
{
    public function test_listener_queues_cdek_order_creation(): void
    {
        Bus::fake();

        $order = new Order(['payment_status' => 'paid']);
        $order->id = 42;
        $order->setRelation('deliveryMethod', new DeliveryMethod(['code' => 'cdek_pickup']));

        (new CreateCdekOrderAfterPayment())->handle(new OrderPaid($order));

        Bus::assertDispatched(CreateCdekOrderJob::class, fn (CreateCdekOrderJob $job) => $job->orderId === 42);
    }

    public function test_order_observer_dispatches_paid_event(): void
    {
        Event::fake([OrderPaid::class]);

        $freshOrder = new Order(['payment_status' => 'paid']);
        $order = Mockery::mock(Order::class)->makePartial();
        $order->shouldReceive('isDirty')->once()->with('payment_status')->andReturnTrue();
        $order->shouldReceive('isPaid')->once()->andReturnTrue();
        $order->shouldReceive('fresh')->once()->with(['deliveryMethod'])->andReturn($freshOrder);

        (new OrderObserver())->updated($order);

        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event) => $event->order === $freshOrder);
    }
}
