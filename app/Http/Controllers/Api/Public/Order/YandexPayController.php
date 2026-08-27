<?php

namespace App\Http\Controllers\Api\Public\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payment\YandexPayService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Публичные endpoint'ы оплаты Яндекс Пэй по viewToken заказа.
 * См. docs/tasks/yandex-pay-integration.md.
 */
class YandexPayController extends Controller
{
    /** Создаёт заказ Яндекс Пэй и отдаёт ссылку оплаты для Web SDK. */
    public function intent(string $viewToken, YandexPayService $service): JsonResponse
    {
        if (! $service->isAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Оплата Яндекс Пэй временно недоступна.',
            ], 503);
        }

        $order = $this->order($viewToken);
        if (! $order || ! $service->supports($order->payment_method)) {
            return response()->json([
                'success' => false,
                'message' => 'Для этого заказа недоступна оплата Яндекс Пэй.',
            ], 422);
        }

        try {
            return response()->json(['success' => true, ...$service->intent($order)]);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось подготовить оплату. Попробуйте ещё раз.',
            ], 500);
        }
    }

    /**
     * Сверяет статус оплаты с Merchant API и отдаёт локальный статус заказа.
     *
     * Нужен после возврата с формы: redirect сам по себе оплату не
     * подтверждает, а уведомление может прийти с задержкой.
     */
    public function status(string $viewToken, YandexPayService $service): JsonResponse
    {
        $order = $this->order($viewToken);
        if (! $order || ! $service->supports($order->payment_method)) {
            return response()->json([
                'success' => false,
                'message' => 'Для этого заказа недоступна оплата Яндекс Пэй.',
            ], 422);
        }

        try {
            $service->syncStatus($order);
        } catch (\Throwable $exception) {
            // Сверка — вспомогательный механизм. Даже если Merchant API
            // недоступен, покупатель должен увидеть текущий статус заказа.
            report($exception);
        }

        $order->refresh();

        return response()->json([
            'success' => true,
            'payment_status' => [
                'value' => $order->payment_status?->value ?? (string) $order->payment_status,
                'label' => $order->payment_status?->label(),
            ],
        ]);
    }

    private function order(string $viewToken): ?Order
    {
        return Order::query()
            ->with(['address', 'client'])
            ->where('view_token', $viewToken)
            ->first();
    }
}
