<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MoySklad\DemandService;
use App\Services\MoySklad\MoySkladSettings;
use App\Services\MoySklad\OrderService;
use App\Services\MoySklad\PaymentInService;
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
 * (demand), когда заказ передан в доставку (статус «Отгружен»). До этого товар
 * только зарезервирован (reserve), а оплата передаётся входящим платежом. Если
 * оплата не поступит за 2 часа, автоотмена (см. CancelUnpaidOrdersCommand)
 * отменяет заказ, и МойСклад снимает резерв.
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

    /** Отгрузка создаётся, когда заказ уже передан в доставку. */
    public static function shouldShip(Order $order): bool
    {
        return in_array($order->status, [
            \App\Enums\OrderStatus::SHIPPED,
            \App\Enums\OrderStatus::DELIVERED,
        ], true);
    }

    /** Входящий платёж нужен оплаченным заказам, которые не отменены. */
    public static function shouldRegisterPayment(Order $order): bool
    {
        return $order->isPaid() && $order->status !== \App\Enums\OrderStatus::CANCELLED;
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

            // Оплата передаётся входящим платежом: «Оплачено» в заказе МойСклад
            // заполняется, и заказ не считается неоплаченным.
            if (self::shouldRegisterPayment($order)) {
                app(PaymentInService::class)->registerPayment($order->fresh());
            }

            // Отгрузка (списание товара) создаётся только когда заказ передан в
            // доставку (статус «Отгружен» и дальше). До этого товар лишь зарезервирован.
            // Идемпотентно — shipOrder() не создаст повторную отгрузку.
            if (self::shouldShip($order)) {
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
