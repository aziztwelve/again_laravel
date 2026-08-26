<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Единый домен проекта: витрина, дашборд и API живут на одном origin, поэтому
| список источников целиком выводится из APP_URL/FRONTEND_URL — домен здесь
| не зашивается. Локальные dev-порты добавляются только вне production.
| Дополнительные источники (например staging-витрина) — через CORS_EXTRA_ORIGINS
| (список через запятую).
|
*/

$origins = [
    env('APP_URL'),
    env('FRONTEND_URL'),
];

foreach (explode(',', (string) env('CORS_EXTRA_ORIGINS', '')) as $extra) {
    $origins[] = trim($extra);
}

if (env('APP_ENV') !== 'production') {
    $origins = array_merge($origins, [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:8080',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:8080',
    ]);
}

$origins = array_values(array_unique(array_filter(array_map(
    static fn ($origin) => is_string($origin) ? rtrim(trim($origin), '/') : null,
    $origins
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register', '*', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
