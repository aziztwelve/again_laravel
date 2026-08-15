<?php

use App\Console\Commands\SyncEmailMessages;
use App\Console\Commands\CheckDiscountsValidity;
use App\Jobs\PollYandexDeliveryStatusesJob;
use App\Jobs\PollCdekDeliveryStatusesJob;
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

// Брошенные корзины: три касания через 2/24/48 ч от последней активности,
// без ограничения по часовому поясу. См.
// docs/tasks/abandoned-cart.md.
Schedule::command('cart:process-abandoned')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Чистка пустых/протухших гостевых корзин (универсальная корзина) — раз в сутки.
// См. docs/tasks/universal-cart.md.
Schedule::command('cart:gc-guest-carts')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// У NDD Platform API нет вебхуков: обновляем незавершённые заявки опросом.
Schedule::job(new PollYandexDeliveryStatusesJob())
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::job(new PollCdekDeliveryStatusesJob())
    ->everyTenMinutes()
    ->withoutOverlapping();
