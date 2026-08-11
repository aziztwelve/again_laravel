<?php

namespace App\Services\Delivery\Yandex;

class CustomerStatusMapper
{
    private const MAP = [
        'created' => 'delivery_created',
        'new' => 'delivery_created',
        'estimating' => 'delivery_created',
        'ready_for_approval' => 'delivery_created',
        'accepted' => 'delivery_created',
        'confirmed' => 'delivery_created',

        'courier_assigned' => 'handed_over',
        'performer_lookup' => 'handed_over',
        'performer_found' => 'handed_over',
        'pickup_arrived' => 'handed_over',
        'picked_up' => 'handed_over',
        'pickuped' => 'handed_over',
        'received_at_warehouse' => 'handed_over',

        'in_transit' => 'in_transit',
        'transportation' => 'in_transit',
        'ready_for_last_mile' => 'in_transit',
        'last_mile_started' => 'in_transit',

        'ready_for_pickup' => 'ready_for_pickup',
        'ready_for_pickup_point' => 'ready_for_pickup',
        'delivered_to_pickup_point' => 'ready_for_pickup',

        'delivery_arrived' => 'courier_today',
        'courier_arrived' => 'courier_today',

        'delivered' => 'delivered',
        'delivered_finish' => 'delivered',

        'returning' => 'returning',
        'return_arrived' => 'returning',
        'returned' => 'returning',
        'returned_finish' => 'returning',

        'cancelled' => 'cancelled',
        'cancelled_by_taxi' => 'cancelled',
        'cancelled_with_payment' => 'cancelled',

        'failed' => 'delivery_problem',
        'estimating_failed' => 'delivery_problem',
        'performer_not_found' => 'delivery_problem',
        'delivery_failed' => 'delivery_problem',
    ];

    public function toCustomer(?string $status): ?string
    {
        return self::MAP[strtolower(trim((string) $status))] ?? null;
    }
}
