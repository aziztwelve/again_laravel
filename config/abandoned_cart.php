<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Брошенная корзина
    |--------------------------------------------------------------------------
    | Параметры триггерной цепочки напоминаний. См. docs/tasks/abandoned-cart.md.
    */

    // Глобальный выключатель фичи (рассылка напоминаний).
    'enabled' => (bool) env('ABANDONED_CART_ENABLED', true),

    // Через сколько часов бездействия активная корзина считается брошенной.
    // «Бездействие» = COALESCE(cart.updated_at, cart.created_at).
    'abandon_after_hours' => (int) env('ABANDONED_CART_ABANDON_AFTER_HOURS', 24),

    // Шаги цепочки. after_hours — офсет от cart.abandoned_at.
    // Шаг 1 (0ч) уходит сразу при детекте брошенной корзины (≈24ч после
    // последней активности — соответствует ТЗ «через 24ч»). Шаг 2 — позже.
    'steps' => [
        ['step' => 1, 'after_hours' => 0],
        ['step' => 2, 'after_hours' => 48],
    ],

    // Приоритет каналов (решение #4). Берём первый, для которого у клиента есть
    // контакт. Каналы из App\Services\Notifications\NotificationService.
    'channel_priority' => ['telegram', 'email', 'whatsapp', 'vk'],

    // Ручная отправка напоминания из админки (шаг F): минимальный интервал между
    // ручными отправками на одну корзину (анти-спам). См. docs/tasks/abandoned-cart.md.
    'manual_throttle_minutes' => (int) env('ABANDONED_CART_MANUAL_THROTTLE_MINUTES', 10),

    // Промокод-стимул на последнем шаге цепочки (фаза 2). По умолчанию выключен.
    // На указанном шаге корзине выдаётся персональный одноразовый промокод
    // (через PromoCode), код сохраняется в cart.recovery_promo_code и попадает в
    // текст письма/сообщения. См. docs/tasks/abandoned-cart.md.
    'promo' => [
        'enabled' => (bool) env('ABANDONED_CART_PROMO_ENABLED', false),
        'step' => (int) env('ABANDONED_CART_PROMO_STEP', 2),
        'discount_type' => env('ABANDONED_CART_PROMO_TYPE', 'percentage'), // percentage|fixed
        'discount_amount' => (float) env('ABANDONED_CART_PROMO_AMOUNT', 10),
        'ttl_days' => (int) env('ABANDONED_CART_PROMO_TTL_DAYS', 7),
        'code_prefix' => env('ABANDONED_CART_PROMO_PREFIX', 'CART'),
    ],

    // Окно отправки по TZ магазина (config('app.timezone')). Сообщения шлём
    // только в [start, end). Ночные срабатывания откладываются до следующего
    // запуска в окне (решение #3).
    'send_window' => [
        'start_hour' => (int) env('ABANDONED_CART_WINDOW_START', 10),
        'end_hour' => (int) env('ABANDONED_CART_WINDOW_END', 21),
    ],

    // База для ссылки восстановления корзины: {recovery_url}/{token}.
    // По умолчанию — витрина (FRONTEND_URL) + /cart/restore.
    'recovery_url' => env(
        'CART_RECOVERY_URL',
        rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/').'/cart/restore'
    ),
];
