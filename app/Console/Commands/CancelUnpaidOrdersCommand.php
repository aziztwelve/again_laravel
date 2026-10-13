<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Order\OrderCreationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Автоотмена неоплаченных заказов: новые онлайн-заказы витрины ждут 24 часа,
 * остальные — 2 часа после оформления. Если срок истёк
 * и до сих пор не оплачен, переводим его в статус «Отменен» —
 * OrderCreationService::cancelOrder() уже ставит в очередь и обновление
 * документа в МойСклад (state -> «Отменен»), и возврат товара на склад
 * (ReturnOrderStockToMoySkladJob, если товар был списан через demand при
 * создании заказа — см. SyncOrderToMoySkladJob).
 *
 * Проверяются заказы в статусах «Новый» и «В работе» — если менеджер
 * перевёл заказ в работу, но оплата не поступила до истечения срока,
 * он тоже подлежит автоотмене.
 *
 * См. docs/tasks/order-status-actualization.md.
 */
class CancelUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Отменить неоплаченные заказы с истёкшим сроком брони (24 часа для новых онлайн-заказов витрины, иначе 2 часа)';

    private const UNPAID_TIMEOUT_HOURS = 2;

    public function handle(OrderCreationService $orderCreationService): int
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::NEW->value, OrderStatus::PROCESSING->value])
            ->where('payment_status', '!=', PaymentStatus::PAID->value)
            ->where(function ($query) {
                $query->where('reservation_expires_at', '<=', now())
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('reservation_expires_at')
                            ->where('created_at', '<=', now()->subHours(self::UNPAID_TIMEOUT_HOURS));
                    });
            })
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Нет неоплаченных заказов с истёкшим сроком оплаты.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($orders as $order) {
            try {
                $success = $orderCreationService->cancelOrder(
                    $order,
                    $order->reservation_expires_at
                        ? 'Автоотмена: истёк срок брони 24 часа, заказ не оплачен'
                        : 'Автоотмена: заказ не оплачен в течение 2 часов'
                );

                if ($success) {
                    $cancelled++;
                }
            } catch (\Throwable $e) {
                Log::error('CancelUnpaidOrdersCommand: не удалось отменить заказ', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Отменено неоплаченных заказов: {$cancelled} из {$orders->count()}.");

        return self::SUCCESS;
    }
}
