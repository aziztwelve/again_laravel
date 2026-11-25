<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Services\Delivery\PochtaDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PochtaDeliveryController
{
    public function __construct(private PochtaDeliveryService $service)
    {
    }

    /** GET /api/public/delivery/pochta/offices?city=...[&latitude=..&longitude=..] */
    public function offices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => 'required|string|min:2|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $offices = $this->service->offices(
                $data['city'],
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage(), 'offices' => []], 503);
        }

        return response()->json(['success' => true, 'offices' => $offices]);
    }

    /** POST /api/public/delivery/pochta/calculate */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_method_code' => 'required|string|in:russian_post_office,russian_post_courier,international_pickup,international_courier',
            'destination' => 'required|array',
            'destination.index' => 'nullable|string|max:20',
            'destination.city' => 'nullable|string|max:255',
            'destination.address' => 'nullable|string|max:255',
            'destination.country_code' => 'nullable|string|size:2',
            'items' => 'required|array|min:1',
            'items.*.name' => 'nullable|string|max:255',
            // Измерения приходят строками из decimal-кастов ("350.000"); пустые заменяет default_package.
            'items.*.weight' => 'nullable|numeric|min:0.001',
            'items.*.length' => 'nullable|numeric|min:0.1',
            'items.*.width' => 'nullable|numeric|min:0.1',
            'items.*.height' => 'nullable|numeric|min:0.1',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'nullable|integer|min:1',
        ]);

        try {
            $tariffs = $this->service->calculateTariffs($data['delivery_method_code'], $data['destination'], $data['items']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 503);
        }

        return response()->json(['success' => true, 'tariffs' => $tariffs]);
    }
}
