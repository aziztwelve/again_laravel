<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\OrderDiscountService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Одноразовая команда пересчёта заказов с привязанным промокодом.
 *
 * Зачем: после фикса согласованности проверок применимости промо
 * (PromoCode::isApplicableToProduct ↔ OrderValidationService::isPromoApplicableToProduct)
 * и фикса учёта discount_behavior в recalculatePromoForExistingItems —
 * старые заказы в БД могут иметь некорректные total_items_discount /
 * total_promo_discount / item.price / item.discount / pivot.applied_amount.
 *
 * Команда прогоняет OrderDiscountService::recalculate() по заказам с
 * promo_code_id != null. Метод идемпотентен.
 *
 * Примеры:
 *   php artisan orders:recalc-promo --dry-run
 *   php artisan orders:recalc-promo --id=67799
 *   php artisan orders:recalc-promo --since=2025-01-01 --chunk=200
 */
class RecalcOrdersWithPromo extends Command
{
    protected $signature = 'orders:recalc-promo
        {--id=* : Пересчитать только указанные order_id (можно несколько)}
        {--since= : Только заказы, созданные с указанной даты (YYYY-MM-DD)}
        {--chunk=100 : Размер чанка}
        {--dry-run : Только показать список заказов, не пересчитывать}';

    protected $description = 'Bulk-пересчёт заказов с привязанным промокодом (после фикса discount_behavior)';

    public function handle(OrderDiscountService $orderDiscountService): int
    {
        $ids   = (array) $this->option('id');
        $since = $this->option('since');
        $chunk = max(1, (int) $this->option('chunk'));
        $dry   = (bool) $this->option('dry-run');

        $query = Order::query()->whereNotNull('promo_code_id');
        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }
        if ($since) {
            $query->where('created_at', '>=', $since);
        }
        $query->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Нет заказов под критерии.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s: %d заказ(ов) под критерии%s.',
            $dry ? 'DRY-RUN' : 'Старт',
            $total,
            $since ? " (с {$since})" : ''
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $failed = [];
        $changed = [];

        $query->chunkById($chunk, function ($orders) use (
            $orderDiscountService, $dry, $bar, &$ok, &$failed, &$changed
        ) {
            foreach ($orders as $order) {
                $before = [
                    'items'  => (float) $order->total_items_discount,
                    'promo'  => (float) $order->total_promo_discount,
                    'amount' => (float) $order->total_amount,
                ];

                if ($dry) {
                    $bar->advance();
                    continue;
                }

                try {
                    $orderDiscountService->recalculate($order->fresh());
                    $fresh = Order::select('id', 'total_items_discount', 'total_promo_discount', 'total_amount')
                        ->find($order->id);

                    $after = [
                        'items'  => (float) $fresh->total_items_discount,
                        'promo'  => (float) $fresh->total_promo_discount,
                        'amount' => (float) $fresh->total_amount,
                    ];

                    if (
                        abs($before['items']  - $after['items'])  > 0.01 ||
                        abs($before['promo']  - $after['promo'])  > 0.01 ||
                        abs($before['amount'] - $after['amount']) > 0.01
                    ) {
                        $changed[] = compact('order') + [
                            'id'     => $order->id,
                            'before' => $before,
                            'after'  => $after,
                        ];
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $failed[] = [
                        'id'    => $order->id,
                        'error' => $e->getMessage(),
                    ];
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dry) {
            $this->info("DRY-RUN завершён. Готово к пересчёту: {$total} заказ(ов).");
            return self::SUCCESS;
        }

        $this->info("Пересчитано: {$ok} из {$total}. Изменилось: ".count($changed).". Ошибок: ".count($failed).'.');

        if (! empty($changed) && $this->getOutput()->isVerbose()) {
            $this->table(
                ['order_id', 'items: было→стало', 'promo: было→стало', 'amount: было→стало'],
                array_map(fn ($r) => [
                    $r['id'],
                    sprintf('%.2f → %.2f', $r['before']['items'],  $r['after']['items']),
                    sprintf('%.2f → %.2f', $r['before']['promo'],  $r['after']['promo']),
                    sprintf('%.2f → %.2f', $r['before']['amount'], $r['after']['amount']),
                ], $changed)
            );
        }

        if (! empty($failed)) {
            $this->error('Ошибки:');
            $this->table(['order_id', 'error'], array_map(
                fn ($r) => [$r['id'], $r['error']],
                $failed
            ));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
