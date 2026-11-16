<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NewOrderReceiptDefaultTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_order_has_receipt_enabled_by_default(): void
    {
        $order = Order::create(['order_number' => 'RC-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending', 'total_amount' => 100]);

        $this->assertFalse((bool) $order->fresh()->no_receipt);
    }

    public function test_manager_can_still_mark_an_order_as_no_receipt(): void
    {
        $order = Order::create(['order_number' => 'RC-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending', 'total_amount' => 100, 'no_receipt' => true]);

        $this->assertTrue((bool) $order->fresh()->no_receipt);
    }
}
