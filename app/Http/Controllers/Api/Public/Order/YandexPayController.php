<?php

namespace App\Http\Controllers\Api\Public\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payment\YandexPayService;
use Illuminate\Http\JsonResponse;

class YandexPayController extends Controller
{
    public function intent(string $viewToken, YandexPayService $service): JsonResponse
    {
        $order = Order::query()->with(['address', 'client'])->where('view_token', $viewToken)->first();
        if (! $order || ! in_array($order->payment_method, ['yandex_pay', 'yandex_pay_split'], true)) {
            return response()->json(['success' => false, 'message' => 'Для этого заказа недоступна оплата Яндекс Пэй.'], 422);
        }
        try {
            return response()->json(['success' => true, ...$service->intent($order)]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
