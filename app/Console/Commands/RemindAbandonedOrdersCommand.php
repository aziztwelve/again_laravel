<?php

namespace App\Console\Commands;

use App\Services\Notifications\AbandonedOrderService;
use Illuminate\Console\Command;

class RemindAbandonedOrdersCommand extends Command
{
    protected $signature = 'orders:remind-abandoned';
    protected $description = 'Напомнить о заказах через 30 минут после открытия страницы без перехода к оплате';

    public function handle(AbandonedOrderService $service): int
    {
        if (! config('abandoned_order.enabled')) {
            return self::SUCCESS;
        }
        $service->dueOrders()->with('client.profile', 'address')->chunkById(100, function ($orders) use ($service) {
            foreach ($orders as $order) {
                $service->remind($order);
            }
        });

        return self::SUCCESS;
    }
}
