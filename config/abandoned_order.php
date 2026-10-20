<?php

return [
    'enabled' => (bool) env('ABANDONED_ORDER_ENABLED', true),
    'after_minutes' => 30,
    'reservation_hours' => 2,
];
