<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MoySklad\MoySkladSettings;
use App\Services\MoySklad\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Передаёт в МойСклад только треки заказа. Полная выгрузка (SyncOrderToMoySkladJob) здесь
 * не нужна: она приводит статус МойСклад к статусу сайта и затирает статус склада.
 */
class PushOrderTracksToMoySkladJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        if (! MoySkladSettings::isConfigured()) {
            return;
        }

        app(OrderService::class)->pushTracks(Order::query()->findOrFail($this->orderId));
    }
}
