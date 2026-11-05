<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Services\PaymentService;
use App\Services\DeliveryManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('delivery', function ($app) {
            return new DeliveryManager();
        });
        $this->app->singleton('payment', function ($app) {
            return new PaymentService(config('payment'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Очередь и планировщик работают от root, веб-сервер — от www-data. Папки,
        // созданные root с umask 0022 (права 0755), закрывали запись веб-серверу:
        // вложения чатов из вебхуков молча терялись. Группа должна писать.
        if ($this->app->runningInConsole()) {
            umask(0002);
        }

        // Триггер «товар появился в наличии» для рассылки подписчикам «Скоро в продаже».
        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
        \App\Models\ProductVariant::observe(\App\Observers\ProductVariantObserver::class);

        // Автоматическое переключение статуса заказа по статусу доставки
        // (см. docs/tasks/order-status-actualization.md, пункт 2).
        \App\Models\YandexOrder::observe(\App\Observers\YandexOrderObserver::class);
        \App\Models\CdekOrder::observe(\App\Observers\CdekOrderObserver::class);
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
    }

    public static function setUrlsToHttps(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->setPath(preg_replace('/^http:/', 'https:', $paginator->path()));

        return $paginator;
    }
}
