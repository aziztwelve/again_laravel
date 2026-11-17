<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\GiftCard\GiftCardService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Оплачен заказ с подарочной картой: включить карту и отправить её получателю.
 * Сбой не должен ломать проведение оплаты.
 *
 * Регистрируется явно в EventServiceProvider. Параметр handle без типа: иначе Laravel найдёт слушатель
 * в app/Listeners по типу параметра сам, и он сработает дважды.
 */
class ActivateGiftCardsAfterPayment
{
    public function handle(mixed $event): void
    {
        if (! $event instanceof OrderPaid) {
            return;
        }

        try {
            app(GiftCardService::class)->activatePaidOrderCards($event->order);
        } catch (Throwable $exception) {
            Log::error('Failed to activate gift cards after payment', [
                'order_id' => $event->order->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
