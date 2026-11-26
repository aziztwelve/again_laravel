<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payment\RobokassaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Страховка к Result URL: сверяет с Robokassa недавние неоплаченные попытки (оплата могла пройти, а уведомление
 * не дойти) и довозвращает статусы заявок на возврат. См. docs/integrations/robokassa.md.
 */
class ReconcileRobokassaPaymentsCommand extends Command
{
    protected $signature = 'robokassa:reconcile {--limit=60 : максимум платежей за запуск}';

    protected $description = 'Сверить платежи Robokassa с кабинетом: пропущенные оплаты, статусы возвратов';

    public function handle(RobokassaService $service): int
    {
        if (! $service->isAvailable()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $paid = 0;

        // Попытки старше 3 минут (покупатель мог ещё платить) и не старше 3 часов (дальше бронь уже снята).
        $pending = Payment::query()
            ->where('provider', RobokassaService::PROVIDER)
            ->where('status', Payment::STATUS_PENDING)
            ->whereBetween('created_at', [now()->subHours(3), now()->subMinutes(3)])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $payment) {
            try {
                if ($service->syncPayment($payment) === 'paid') {
                    $paid++;
                    Log::warning('Robokassa: оплата найдена при сверке (Result URL не дошёл)', ['payment_id' => $payment->id]);
                }
            } catch (\Throwable $e) {
                Log::warning('Robokassa: сверка платежа не удалась', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        $refunds = Payment::query()
            ->where('provider', RobokassaService::PROVIDER)
            ->where('provider_data->refund->label', 'processing')
            ->limit($limit)
            ->get();

        foreach ($refunds as $payment) {
            try {
                $service->syncRefund($payment);
            } catch (\Throwable $e) {
                Log::warning('Robokassa: сверка возврата не удалась', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Сверено платежей: {$pending->count()} (найдено оплат: {$paid}), возвратов: {$refunds->count()}");

        return self::SUCCESS;
    }
}
