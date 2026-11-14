<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Notifications\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Письмо покупателю «Оплата получена». Сбой отправки не должен ломать проведение оплаты.
 *
 * Регистрируется явно в EventServiceProvider. Параметр handle намеренно без типа: Laravel сам
 * находит слушатели в app/Listeners по типу параметра, и слушатель сработал бы дважды.
 */
class NotifyCustomerOrderPaid
{
    public function handle(mixed $event): void
    {
        if (! $event instanceof OrderPaid) {
            return;
        }

        try {
            app(OrderNotificationService::class)->notifyOrderPaid($event->order);
        } catch (Throwable $exception) {
            Log::error('Failed to queue the order paid notification', [
                'order_id' => $event->order->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
