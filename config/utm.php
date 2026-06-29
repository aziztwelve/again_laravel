<?php

return [
    /*
    |--------------------------------------------------------------------------
    | База публичного трекера
    |--------------------------------------------------------------------------
    | Домен, на котором менеджер раздаёт ссылки вида /go/{slug}. Он должен
    | совпадать с доменом витрины/чекаута, иначе host-only cookie utm_link_id
    | не попадёт в заказ.
    |
    | Единый домен проекта — sub.againdev.ru: витрина, дашборд и API на одном
    | origin, поэтому по умолчанию база = APP_URL (через FRONTEND_URL, который
    | теперь тоже равен APP_URL). Отдельный UTM_TRACKING_BASE_URL нужен только
    | для нестандартных окружений.
    */
    'tracking_base_url' => env('UTM_TRACKING_BASE_URL', env('FRONTEND_URL', env('APP_URL'))),

    /*
    |--------------------------------------------------------------------------
    | Кука атрибуции UTM
    |--------------------------------------------------------------------------
    | Куку utm_link_id ставит редирект-трекер GET /go/{slug}, а читает её
    | api-чекаут. Имя куки фиксировано ('utm_link_id') и исключено из шифрования
    | в bootstrap/app.php — не меняйте без правки except-списка.
    |
    | Единый домен (sub.againdev.ru): /go и чекаут на одном origin, поэтому
    | кука — host-only (cookie_domain не задаём), SameSite=Lax, Secure=true
    | (домен на HTTPS). Кросс-доменная схема (Domain=.example.com,
    | SameSite=None) больше не нужна — оставлена в env только на случай отката.
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
