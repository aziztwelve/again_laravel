<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Order\OrderCreationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Автоотмена неоплаченных заказов: если заказ создан более 2 часов назад
 * и до сих пор не оплачен, переводим его в статус «Отменен» —
 * OrderCreationService::cancelOrder() уже ставит в очередь и обновление
 * документа в МойСклад (state -> «Отменен»), и возврат товара на склад
 * (ReturnOrderStockToMoySkladJob, если товар был списан через demand при
 * создании заказа — см. SyncOrderToMoySkladJob).
 *
 * Проверяются заказы в статусах «Новый» и «В работе» — если менеджер
 * перевёл заказ в работу, но оплата так и не поступила за 2 часа,
 * он тоже подлежит автоотмене.
 *
 * См. docs/tasks/order-status-actualization.md.
 */
class CancelUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Отменить заказы, не оплаченные в течение 2 часов после создания';

    private const UNPAID_TIMEOUT_HOURS = 2;

    public function handle(OrderCreationService $orderCreationService): int
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::NEW->value, OrderStatus::PROCESSING->value])
            ->where('payment_status', '!=', PaymentStatus::PAID->value)
            ->where('created_at', '<=', now()->subHours(self::UNPAID_TIMEOUT_HOURS))
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Нет неоплаченных заказов старше 2 часов.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($orders as $order) {
            try {
                $success = $orderCreationService->cancelOrder(
                    $order,
                    'Автоотмена: заказ не оплачен в течение 2 часов'
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
