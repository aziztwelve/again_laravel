<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\CreateCdekOrderJob;

/**
 * @deprecated Временно не зарегистрирован: менеджер создаёт заявку СДЭК
 * вручную из админки. Вернуть listener в EventServiceProvider при включении
 * автоматического создания после оплаты.
 */
class CreateCdekOrderAfterPayment
{
    /** @param OrderPaid $event */
    public function handle(mixed $event): void
    {
        if (! $event instanceof OrderPaid) {
            return;
        }

        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'cdek_')) {
            CreateCdekOrderJob::dispatch($event->order->id);
        }
    }
}
