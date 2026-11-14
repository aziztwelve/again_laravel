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

        // Реальные статусы Платформы доставки (request/info → state.status). Раньше здесь были только
        // названия старого API, поэтому покупатель по этим статусам не получал ни одного уведомления.
        'sorting_center_loaded' => 'delivery_created',
        'sorting_center_prepared' => 'delivery_created',
        'sorting_center_at_start' => 'delivery_created',
        'sorting_center_transmitted' => 'handed_over',
        'delivery_loaded' => 'in_transit',
        'delivery_at_start' => 'in_transit',
        'delivery_at_start_sort' => 'in_transit',
        'delivery_transportation' => 'in_transit',
        'delivery_transportation_recipient' => 'in_transit',
        'delivery_arrived_pickup_point' => 'ready_for_pickup',
        'delivery_transmitted_to_recipient' => 'delivered',
        'delivery_delivered' => 'delivered',
        'finished' => 'delivered',
        'return_preparing' => 'returning',
        'return_arrived_delivery' => 'returning',
        'return_transmitted_fulfilment' => 'returning',
        'return_ready_for_pickup' => 'returning',
        'return_returned' => 'returning',
        'delivery_storage_period_expired' => 'returning',
        'cancelled_user' => 'cancelled',
        'cancelled_by_delivery' => 'cancelled',
        'validating_error' => 'delivery_problem',
        'error' => 'delivery_problem',
        'sorting_center_error' => 'delivery_problem',

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
