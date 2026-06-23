<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Кука атрибуции UTM
    |--------------------------------------------------------------------------
    | Куку utm_link_id ставит редирект-трекер GET /go/{slug}, а читает её
    | api-чекаут. Имя куки фиксировано ('utm_link_id') и исключено из шифрования
    | в bootstrap/app.php — не меняйте без правки except-списка.
    |
    | Для кросс-доменной схемы (витрина и API на разных origin) задайте:
    |   UTM_COOKIE_DOMAIN=.example.com   (общий родительский домен)
    |   UTM_COOKIE_SAMESITE=none
    |   UTM_COOKIE_SECURE=true           (SameSite=None требует Secure + HTTPS)
    */
    'attribution' => [
        'cookie_minutes' => (int) env('UTM_COOKIE_MINUTES', 60 * 24 * 30), // 30 дней
        'cookie_domain' => env('UTM_COOKIE_DOMAIN'),                        // null → текущий хост
        'cookie_same_site' => env('UTM_COOKIE_SAMESITE', 'lax'),           // lax|none|strict
        'cookie_secure' => env('UTM_COOKIE_SECURE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Разрешённые хосты целевых ссылок (target_url)
    |--------------------------------------------------------------------------
    | Список хостов через запятую. Если пусто — разрешён любой хост
    | (по ТЗ «любая страница сайта»). Заполните, чтобы ограничить метки
    | только своими доменами и закрыть редирект на сторонние сайты.
    |   UTM_ALLOWED_TARGET_HOSTS=example.com,www.example.com
    */
    'allowed_target_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('UTM_ALLOWED_TARGET_HOSTS', ''))
    ))),
];
