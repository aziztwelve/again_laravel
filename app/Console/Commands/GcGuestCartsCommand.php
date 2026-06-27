<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Чистка серверных гостевых корзин (client_id IS NULL), чтобы таблица cart не
 * распухала от ботов/случайных заходов (универсальная корзина — см.
 * docs/tasks/universal-cart.md).
 *
 * Удаляет:
 *  1) пустые гостевые корзины (без позиций) старше empty_guest_ttl_hours;
 *  2) протухшие гостевые корзины (active/abandoned) неактивные дольше
 *     guest_retention_days.
 *
 * Никогда не трогает клиентские (client_id != NULL) и оформленные (ordered)
 * корзины. cart_items удаляются явно (FK cart_items.cart_id = ON DELETE SET NULL,
 * не cascade); cart_communications удаляются каскадом.
 */
class GcGuestCartsCommand extends Command
{
    protected $signature = 'cart:gc-guest-carts {--dry-run : Только посчитать, без удаления}';

    protected $description = 'Удалить пустые и протухшие гостевые корзины';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $emptyThreshold = now()->subHours((int) config('cart.gc.empty_guest_ttl_hours', 48));
        $staleThreshold = now()->subDays((int) config('cart.gc.guest_retention_days', 90));

        try {
            $empty = $this->purge(
                Cart::query()
                    ->whereNull('client_id')
                    ->where('status', '!=', 'ordered')
                    ->whereDoesntHave('items')
                    ->whereRaw('COALESCE(last_activity_at, updated_at, created_at) <= ?', [$emptyThreshold]),
                $dryRun
            );

            $stale = $this->purge(
                Cart::query()
                    ->whereNull('client_id')
                    ->whereIn('status', ['active', 'abandoned'])
                    ->whereRaw('COALESCE(last_activity_at, updated_at, created_at) <= ?', [$staleThreshold]),
                $dryRun
            );

            $prefix = $dryRun ? '[dry-run] ' : '';
            $this->info("{$prefix}Гостевые корзины: пустых — {$empty}, протухших — {$stale}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('GcGuestCartsCommand: ошибка', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Ошибка: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Удалить корзины по запросу (вместе с позициями). Возвращает кол-во.
     */
    private function purge($query, bool $dryRun): int
    {
        if ($dryRun) {
            return (clone $query)->count();
        }

        $deleted = 0;

        $query->select('cart.*')->chunkById(200, function ($carts) use (&$deleted) {
            foreach ($carts as $cart) {
                $cart->items()->delete();
                $cart->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}
