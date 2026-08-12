<?php

namespace App\Jobs;

use App\Models\YandexOrder;
use App\Services\Notifications\YandexDeliveryNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyYandexDeliveryCreatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $yandexOrderId)
    {
    }

    public function handle(YandexDeliveryNotificationService $notificationService): void
    {
        $yandexOrder = YandexOrder::query()->find($this->yandexOrderId);

        if (! $yandexOrder) {
            return;
        }

        $notificationService->notify($yandexOrder, 'delivery_created');
    }
}
