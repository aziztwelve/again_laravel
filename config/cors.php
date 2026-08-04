<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register', '*', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    // Единый домен проекта — againdev3.ru (витрина + дашборд + API на одном
    // origin). Старые домены витрины выведены из эксплуатации.
    'allowed_origins' => [
        'https://againdev3.ru',
        'https://againdev.ru',

        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
        ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
