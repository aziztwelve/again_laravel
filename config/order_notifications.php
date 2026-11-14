<?php

/**
 * Письмо покупателю «Оплата получена» (событие OrderPaid: платёжный вебхук или ручная отметка оплаты).
 */
return [
    'paid' => [
        'enabled' => (bool) env('ORDER_PAID_NOTIFICATION_ENABLED', true),

        // Не отправлять, если оплата отмечена с датой старше стольких минут. Защита от пачки писем
        // при импорте и массовых правках старых заказов. Оплата без даты считается сегодняшней.
        'max_paid_age_minutes' => (int) env('ORDER_PAID_NOTIFICATION_MAX_PAID_AGE_MINUTES', 120),

        // Не отправлять по заказам, созданным раньше стольких дней назад (оплатили задним числом, импорт).
        'max_order_age_days' => (int) env('ORDER_PAID_NOTIFICATION_MAX_ORDER_AGE_DAYS', 7),
    ],
];
