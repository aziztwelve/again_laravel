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

    // Через сколько минут бездействия корзина входит в цепочку напоминаний.
    // До третьего касания её статус остаётся active.
    // «Бездействие» = COALESCE(last_activity_at, updated_at, created_at).
    'abandon_after_minutes' => (int) env('ABANDONED_CART_ABANDON_AFTER_MINUTES', 30),

    // Шаги цепочки. after_minutes — офсет от начала цепочки (cart.abandoned_at).
    // В сумме с 30 минутами неактивности это 2, 24 и 48 часов от последней
    // активности.
    'steps' => [
        ['step' => 1, 'after_minutes' => 90],
        ['step' => 2, 'after_minutes' => 1410],
        ['step' => 3, 'after_minutes' => 2850],
    ],

    // Список поддерживаемых каналов. Сценарий отправляет сообщение в каждый
    // доступный контакт клиента (см. CustomerChannelResolver).
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
        'step' => (int) env('ABANDONED_CART_PROMO_STEP', 3),
        'discount_type' => env('ABANDONED_CART_PROMO_TYPE', 'percentage'), // percentage|fixed
        'discount_amount' => (float) env('ABANDONED_CART_PROMO_AMOUNT', 10),
        'ttl_days' => (int) env('ABANDONED_CART_PROMO_TTL_DAYS', 7),
        'code_prefix' => env('ABANDONED_CART_PROMO_PREFIX', 'CART'),
    ],

    // База для ссылки восстановления корзины: {recovery_url}/{token}.
    // По умолчанию — витрина (FRONTEND_URL) + /cart/recovery.
    'recovery_url' => env(
        'CART_RECOVERY_URL',
        rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/').'/cart/recovery'
    ),
];
