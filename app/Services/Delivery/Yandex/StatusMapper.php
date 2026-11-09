<?php

namespace App\Services\Delivery\Yandex;

class StatusMapper
{
    private const MAP = [
        'created' => 'created', 'confirmed' => 'created',
        'courier_assigned' => 'courier_assigned', 'picked_up' => 'picked_up',
        'created' => 'created', 'accepted' => 'created', 'confirmed' => 'created',
        'courier_assigned' => 'courier_assigned', 'picked_up' => 'picked_up',
        'delivered' => 'delivered', 'returned' => 'returning', 'cancelled' => 'cancelled', 'failed' => 'failed',
        'new' => 'created', 'estimating' => 'created', 'ready_for_approval' => 'created', 'accepted' => 'created',
        'performer_lookup' => 'courier_assigned', 'performer_found' => 'courier_assigned', 'pickup_arrived' => 'courier_assigned',
        'pickuped' => 'picked_up', 'delivery_arrived' => 'picked_up',
        'delivered' => 'delivered', 'delivered_finish' => 'delivered',
        'returning' => 'returning', 'return_arrived' => 'returning', 'returned' => 'returning', 'returned_finish' => 'returning',
        'cancelled' => 'cancelled', 'cancelled_by_taxi' => 'cancelled', 'cancelled_with_payment' => 'cancelled_paid',
        'failed' => 'failed', 'estimating_failed' => 'failed', 'performer_not_found' => 'failed',

        // Статусы Платформы доставки (request/info → state.status). Раньше они не были известны
        // и все превращались в «created», поэтому заказ не получал «Отгружен» и «Доставлен».
        // Заказ создан и ждёт забора: SORTING_CENTER_LOADED / SORTING_CENTER_PREPARED.
        // Яндекс принял заказ и повёз дальше — на сайте это «Отгружен».
        'sorting_center_transmitted' => 'picked_up', 'delivery_loaded' => 'picked_up',
        'delivery_at_start' => 'picked_up', 'delivery_at_start_sort' => 'picked_up',
        'delivery_transportation' => 'picked_up', 'delivery_transportation_recipient' => 'picked_up',
        'delivery_arrived' => 'picked_up', 'delivery_arrived_pickup_point' => 'picked_up',
        'delivery_storage_period_extended' => 'picked_up', 'delivery_time_intervals_updated' => 'picked_up',
        'delivery_transmitted_to_recipient' => 'delivered', 'delivery_delivered' => 'delivered', 'finished' => 'delivered',
        'return_preparing' => 'returning', 'return_arrived_delivery' => 'returning', 'return_transmitted_fulfilment' => 'returning',
        'return_ready_for_pickup' => 'returning', 'return_returned' => 'returning', 'delivery_storage_period_expired' => 'returning',
        'cancelled_user' => 'cancelled', 'cancelled_by_delivery' => 'cancelled',
        'validating_error' => 'failed', 'error' => 'failed', 'sorting_center_error' => 'failed',
    ];

    public function toInternal(?string $status): string
    {
        return self::MAP[strtolower((string) $status)] ?? 'created';
    }
}
