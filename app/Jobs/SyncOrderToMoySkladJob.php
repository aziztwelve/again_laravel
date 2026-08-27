<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MoySklad\DemandService;
use App\Services\MoySklad\MoySkladSettings;
use App\Services\MoySklad\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Выгружает заказ в МойСклад (создаёт или обновляет документ «Заказ
 * покупателя») и списывает товар со склада через документ «Отгрузка»
 * (demand) сразу при первой синхронизации — товар считается зарезервированным
 * под заказ с момента оформления, независимо от оплаты. Если оплата не
 * поступит за 2 часа, автоотмена (см. App\Console\Commands\CancelUnpaidOrders)
 * вернёт товар на склад через DemandService::returnOrderStock().
 *
 * Вызывается сразу при создании заказа и повторно при оплате/смене
 * статуса/отмене (тогда обновляет уже существующий документ customerorder).
 * Ошибки МойСклад (сервис недоступен, невалидные данные и т.п.) не должны
 * блокировать оформление заказа клиентом — поэтому выполняется асинхронно
 * в очереди.
 */
class SyncOrderToMoySkladJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $orderId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('moysklad-order:'.$this->orderId))->expireAfter(900)];
    }

    /**
     * Сервисы МойСклад резолвятся внутри метода, а не через параметры handle():
     * их конструкторы бросают исключение при отсутствии настроек, и контейнер
     * сделал бы это ещё до проверки isConfigured() ниже.
     */
    public function handle(): void
    {
        $order = Order::query()->findOrFail($this->orderId);

        if (! MoySkladSettings::isConfigured()) {
            // Интеграция не настроена — это не сбой синхронизации, повторять
            // нечего. Иначе заказ трижды уходил бы в retry и попадал в
            // failed_jobs, а при sync-очереди ломал бы ответ чекаута.
            Log::warning('SyncOrderToMoySkladJob: МойСклад не настроен, синхронизация пропущена', [
                'order_id' => $order->id,
            ]);

            return;
        }

        try {
            app(OrderService::class)->pushOrder($order);

            // Списываем товар сразу при первой синхронизации (idempotent —
            // shipOrder() не создаст повторную отгрузку, если она уже есть).
            // Отменённые заказы не списываем — для них возврат/отмена
            // обрабатывается отдельно (см. cancelOrder()).
            if ($order->status !== \App\Enums\OrderStatus::CANCELLED) {
                app(DemandService::class)->shipOrder($order->fresh());
            }
        } catch (\Throwable $e) {
            Log::error('SyncOrderToMoySkladJob: не удалось синхронизировать заказ', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
