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
    public function handle(OrderPaid $event): void
    {
        if (str_starts_with((string) $event->order->deliveryMethod?->code, 'cdek_')) {
            CreateCdekOrderJob::dispatch($event->order->id);
        }
    }
}
