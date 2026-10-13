<?php

return [
    'enabled' => (bool) env('ABANDONED_ORDER_ENABLED', true),
    'after_minutes' => 60,
];
