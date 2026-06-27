<?php

/**
 * Универсальная серверная корзина (см. docs/tasks/universal-cart.md).
 * Настройки гостевой cookie-идентификации и фильтра ботов.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Гостевая cookie
    |--------------------------------------------------------------------------
    | guest_token хранится в HttpOnly-cookie. Для кросс-доменной витрины нужен
    | SameSite=None + Secure (прод, https). Для локальной разработки по http
    | задайте CART_COOKIE_SECURE=false и CART_COOKIE_SAME_SITE=lax.
    |
    | ВАЖНО: имя cookie должно быть в списке исключений шифрования
    | (bootstrap/app.php → encryptCookies except), чтобы значение одинаково
    | читалось в любой middleware-группе (api не шифрует cookie).
    */
    'cookie' => [
        'name' => env('CART_COOKIE_NAME', 'guest_token'),
        'days' => (int) env('CART_GUEST_COOKIE_DAYS', 365),
        'domain' => env('CART_COOKIE_DOMAIN'),         // null → текущий хост
        'secure' => (bool) env('CART_COOKIE_SECURE', true),
        'same_site' => env('CART_COOKIE_SAME_SITE', 'none'), // none|lax|strict
        'path' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Фильтр ботов
    |--------------------------------------------------------------------------
    | Подстроки User-Agent, для которых не создаём серверную гостевую корзину
    | (анти-взрыв таблицы). Используется CartResolver и GC-командой.
    */
    'bot_user_agents' => [
        'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit',
        'yandexbot', 'googlebot', 'ahrefs', 'semrush', 'mj12bot', 'dotbot',
        'headlesschrome', 'python-requests', 'curl', 'wget',
    ],

    /*
    |--------------------------------------------------------------------------
    | GC гостевых корзин (cart:gc-guest-carts)
    |--------------------------------------------------------------------------
    | Чистка серверных гостевых корзин (client_id IS NULL), чтобы таблица не
    | распухала. Никогда не трогаем клиентские и оформленные (ordered) корзины.
    | См. docs/tasks/universal-cart.md.
    */
    'gc' => [
        // Пустые гостевые корзины (без позиций) старше N часов — мусор от
        // ботов/случайных заходов.
        'empty_guest_ttl_hours' => (int) env('CART_GC_EMPTY_GUEST_TTL_HOURS', 48),

        // Любые гостевые корзины (active/abandoned) неактивные дольше N дней —
        // протухшие, цепочка по ним уже завершена.
        'guest_retention_days' => (int) env('CART_GC_GUEST_RETENTION_DAYS', 90),
    ],

];
