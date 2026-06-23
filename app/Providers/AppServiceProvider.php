<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
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

        // Прокси для всех Http:: запросов — Telegram иначе недоступен с RU-хостинга.
        // CURLPROXY_SOCKS5_HOSTNAME (7) = socks5h — DNS резолвится на стороне прокси.
        if (env('TELEGRAM_PROXY')) {
            Http::globalOptions([
                'curl' => [
                    CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
                    CURLOPT_PROXY     => '127.0.0.1',
                    CURLOPT_PROXYPORT => 1080,
                ],
            ]);
        }
    }

    public static function setUrlsToHttps(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->setPath(preg_replace('/^http:/', 'https:', $paginator->path()));

        return $paginator;
    }
}
