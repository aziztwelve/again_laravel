<?php

namespace App\Console\Commands;

use App\Services\Cart\AbandonedCartService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Детект брошенных корзин и отправка триггерной цепочки напоминаний.
 * Запускается планировщиком (см. routes/console.php). Фича «Брошенная
 * корзина» — docs/tasks/abandoned-cart.md.
 */
class ProcessAbandonedCartsCommand extends Command
{
    protected $signature = 'cart:process-abandoned';

    protected $description = 'Пометить брошенные корзины и отправить цепочку напоминаний';

    public function handle(AbandonedCartService $service): int
    {
        if (! config('abandoned_cart.enabled', true)) {
            $this->info('Брошенные корзины: фича отключена (ABANDONED_CART_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $marked = $service->markAbandonedCarts();
            $this->info("Помечено брошенными: {$marked}");

            $result = $service->processChain();

            if (! $result['window']) {
                $this->info('Вне окна отправки — сообщения не отправлялись.');

                return self::SUCCESS;
            }

            $this->info("Отправлено напоминаний: {$result['sent']}, пропущено (нет канала): {$result['skipped']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('ProcessAbandonedCartsCommand: ошибка', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Ошибка: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
