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
 * Передаёт в МойСклад только трек Почты России («Трек номер ПР»). Полная выгрузка заказа
 * (SyncOrderToMoySkladJob) здесь не нужна: она приводит статус МойСклад к статусу сайта.
 */
class PushPostTrackingToMoySkladJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()->findOrFail($this->orderId);
        $number = trim((string) data_get($order->legacy_meta, 'russian_post_tracking_number'));

        if ($number === '' || ! MoySkladSettings::isConfigured()) {
            return;
        }

        app(OrderService::class)->pushTrackingNumber($order, $number, 'Трек номер ПР');
    }
}
