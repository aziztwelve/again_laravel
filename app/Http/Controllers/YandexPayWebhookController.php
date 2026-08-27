<?php

namespace App\Http\Controllers;

use App\Exceptions\YandexPayWebhookException;
use App\Services\Payment\YandexPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Приём уведомлений Яндекс Пэй (`POST /v1/webhook`).
 *
 * Тело приходит как `application/octet-stream` с JWT ES256. Подпись
 * проверяется до разбора payload, статус меняется только после сверки
 * заказа и суммы через Merchant API.
 *
 * На успешную обработку отвечаем 200 — иначе Яндекс Пэй будет повторять
 * доставку уведомления сутки.
 */
class YandexPayWebhookController extends Controller
{
    public function __invoke(Request $request, YandexPayService $service): JsonResponse
    {
        try {
            $service->processWebhook($service->verifiedWebhookPayload($request->getContent()));

            return response()->json(['status' => 'success']);
        } catch (YandexPayWebhookException $exception) {
            report($exception);

            return response()->json([
                'status' => 'fail',
                'reasonCode' => $exception->reasonCode,
                'reason' => $exception->getMessage(),
            ], $exception->status);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'fail',
                'reasonCode' => 'OTHER',
                'reason' => 'Не удалось обработать уведомление.',
            ], 500);
        }
    }
}
