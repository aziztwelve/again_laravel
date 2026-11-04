<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Некоторые сервисы (например, вебхуки CloudPayments) шлют запросы на адрес с
// двойным слэшем (//api/...). nginx сводит его при выборе location, но PHP получает
// исходный REQUEST_URI, и маршрут не находится (404). Нормализуем начало пути.
if (isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '//')) {
    $_SERVER['REQUEST_URI'] = '/'.ltrim($_SERVER['REQUEST_URI'], '/');
}

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
