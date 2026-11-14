<?php

namespace Tests\Feature;

use App\Models\CdekOrder;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicOrderCdekTrackingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_order_page_shows_cdek_number_and_tracking_link(): void
    {
        $order = Order::factory()->create(['view_token' => bin2hex(random_bytes(16)), 'tracking_number' => null, 'moysklad_order_uuid' => null]);
        CdekOrder::create([
            'order_id' => $order->id, 'external_order_number' => 'order-'.$order->id, 'delivery_type' => 'pickup', 'tariff_code' => 136,
            'creation_state' => 'SUCCESSFUL', 'cdek_number' => '10347281436', 'status_code' => 'ACCEPTED_AT_PICK_UP_POINT',
            'tracking_url' => 'https://www.cdek.ru/ru/tracking?order_id=10347281436',
        ]);

        $response = $this->getJson('/api/public/orders/'.$order->view_token)->assertOk();

        $response->assertJsonPath('order.tracking_number', '10347281436');
        $response->assertJsonPath('order.delivery_tracking.tracking_url', 'https://www.cdek.ru/ru/tracking?order_id=10347281436');
    }
}
