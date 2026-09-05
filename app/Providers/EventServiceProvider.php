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

    // Основной framework-provider также сканирует app/Listeners. У deprecated-
    // listener'ов выше параметр handle намеренно не type-hint'ен, поэтому они
    // не будут обнаружены автоматически до явного возврата этой интеграции.
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
