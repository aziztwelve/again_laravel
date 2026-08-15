<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Listeners\CreateYandexOrderAfterPayment;
use App\Listeners\CreateCdekOrderAfterPayment;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Автоматически ставим создание Яндекс.Доставки в очередь только после
     * подтверждённой сервером оплаты. Это не зависит от закрытия браузера.
     */
    protected $listen = [
        OrderPaid::class => [
            CreateYandexOrderAfterPayment::class,
            CreateCdekOrderAfterPayment::class,
        ],
    ];

    // Listener задан явно выше. Отключаем discovery, иначе Laravel добавит
    // тот же CreateYandexOrderAfterPayment второй раз по type-hint события.
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
