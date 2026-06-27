<?php

use App\Console\Commands\SyncEmailMessages;
use App\Console\Commands\CheckDiscountsValidity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('discounts:check-validity', function () {
    $check_discount_validity = new CheckDiscountsValidity();
    $check_discount_validity->handle();
})->purpose('Activate and deactivate discounts')->everyFiveMinutes();


Schedule::command('email:sync')->everyFiveMinutes();

//Schedule::command('birthday:process')->daily();
Schedule::command('birthday:process')->dailyAt('10:00');

Schedule::command('giftcards:send-scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Брошенные корзины: детект + триггерная цепочка напоминаний (24ч/72ч).
// Ограничение по окну отправки 10:00–21:00 — внутри сервиса. См.
// docs/tasks/abandoned-cart.md.
Schedule::command('cart:process-abandoned')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Чистка пустых/протухших гостевых корзин (универсальная корзина) — раз в сутки.
// См. docs/tasks/universal-cart.md.
Schedule::command('cart:gc-guest-carts')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();
