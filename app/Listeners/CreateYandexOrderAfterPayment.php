<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\CreateYandexOrderJob;

/**
 * @deprecated Временно не зарегистрирован: менеджер создаёт заявку
 * Яндекс.Доставки вручную из админки. Вернуть listener в
 * EventServiceProvider при включении автоматического создания после оплаты.
 */
class CreateYandexOrderAfterPayment
{
    public function handle(OrderPaid $event): void
    {
        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'yandex_') && ! $event->order->yandexOrder()->exists()) {
            CreateYandexOrderJob::dispatch($event->order->id);
        }
    }
}
