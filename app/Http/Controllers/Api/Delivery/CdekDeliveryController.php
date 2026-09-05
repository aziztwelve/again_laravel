<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Models\CdekOrder;
use App\Services\Delivery\CdekDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CdekDeliveryController
{
    public function __construct(private CdekDeliveryService $service)
    {
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|min:2|max:255', 'country_code' => 'nullable|string|size:2']);
        return response()->json(['success' => true, 'cities' => $this->service->cities($data['query'], $data['country_code'] ?? 'RU')]);
    }

    public function pickupPoints(Request $request): JsonResponse
    {
        $data = $request->validate(['city_code' => 'required|integer', 'type' => 'nullable|in:PVZ,POSTAMAT,ALL']);
        return response()->json(['success' => true, 'points' => $this->service->pickupPoints($data)]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_type' => 'required|in:courier,pickup,postamat',
            'destination' => 'required|array', 'destination.city_code' => 'required|integer', 'destination.address' => 'nullable|string|max:255',
            'pvz_code' => 'nullable|string|max:255', 'items' => 'required|array|min:1',
            'items.*.name' => 'nullable|string|max:255',
            // Измерения товара приходят строками из decimal-кастов API ("350.000"),
            // отсутствующие поля заполняет fallback из настроек default_package.
            'items.*.weight' => 'nullable|numeric|min:0.001',
            'items.*.length' => 'nullable|numeric|min:0.1',
            'items.*.width' => 'nullable|numeric|min:0.1',
            'items.*.height' => 'nullable|numeric|min:0.1',
            'items.*.price' => 'nullable|numeric|min:0', 'items.*.quantity' => 'nullable|integer|min:1',
        ]);
        try {
            $tariffs = $this->service->calculateTariffs($data['delivery_type'], $data['destination'], $data['items'], $data['pvz_code'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['success' => true, 'tariffs' => $tariffs]);
    }

    /** CDEK sends status callbacks asynchronously; polling remains the source of full order data. */
    public function webhook(Request $request): JsonResponse
    {
        $externalNumber = data_get($request->all(), 'entity.number') ?? data_get($request->all(), 'number');
        if ($externalNumber) {
            $cdekOrder = CdekOrder::query()->where('external_order_number', $externalNumber)->first();
            if ($cdekOrder) \App\Jobs\SyncCdekOrderJob::dispatch($cdekOrder->id);
        }
        return response()->json(['success' => true]);
    }
}
