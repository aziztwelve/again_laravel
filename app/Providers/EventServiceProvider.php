<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Listeners\CreateYandexOrderAfterPayment;
use App\Listeners\CreateCdekOrderAfterPayment;
use App\Listeners\SyncOrderToMoySkladAfterPayment;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Заявки Яндекс.Доставки и СДЭК временно создаются менеджером вручную
     * из карточки заказа. Автосоздание после оплаты оставлено ниже
     * закомментированным, чтобы его можно было безопасно вернуть.
     */
    protected $listen = [
        OrderPaid::class => [
            // @deprecated Временно отключено: заявки доставки создаются вручную из админки.
            // CreateYandexOrderAfterPayment::class,
            // CreateCdekOrderAfterPayment::class,
            SyncOrderToMoySkladAfterPayment::class,
        ],
    ];

    // Discovery отключён: иначе Laravel может повторно включить deprecated-
    // listener'ы создания заявок доставки по type-hint события.
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
