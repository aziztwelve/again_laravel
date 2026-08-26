<?php

namespace App\Http\Controllers;

use App\Services\Payment\YandexPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YandexPayWebhookController extends Controller
{
    public function __invoke(Request $request, YandexPayService $service): JsonResponse
    {
        try {
            $service->processWebhook($service->verifiedWebhookPayload($request->getContent()));
            return response()->json(['status' => 'success']);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['status' => 'fail', 'reasonCode' => 'UNAUTHORIZED', 'reason' => 'Invalid notification.'], 403);
        }
    }
}
