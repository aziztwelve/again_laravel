<?php

namespace App\Services\Delivery\Yandex;

class StatusMapper
{
    private const MAP = [
        'new' => 'created', 'estimating' => 'created', 'ready_for_approval' => 'created', 'accepted' => 'created',
        'performer_lookup' => 'courier_assigned', 'performer_found' => 'courier_assigned', 'pickup_arrived' => 'courier_assigned',
        'pickuped' => 'picked_up', 'delivery_arrived' => 'picked_up',
        'delivered' => 'delivered', 'delivered_finish' => 'delivered',
        'returning' => 'returning', 'return_arrived' => 'returning', 'returned' => 'returning', 'returned_finish' => 'returning',
        'cancelled' => 'cancelled', 'cancelled_by_taxi' => 'cancelled', 'cancelled_with_payment' => 'cancelled_paid',
        'failed' => 'failed', 'estimating_failed' => 'failed', 'performer_not_found' => 'failed',
    ];

    public function toInternal(?string $status): string
    {
        return self::MAP[strtolower((string) $status)] ?? 'created';
    }
}
