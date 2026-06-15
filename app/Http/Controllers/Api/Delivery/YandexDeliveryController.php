<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер интеграции с Yandex Delivery Platform API.
 *
 * Все методы работают через единый Platform API Яндекса
 * (location/detect, pickup-points/list, offers/create, offers/confirm, request/info).
 */
class YandexDeliveryController extends Controller
{
    public function __construct(private YandexDeliveryService $service)
    {
    }

    /**
     * Определение населённого пункта (geo_id) по адресу.
     * GET /api/public/delivery/yandex/location?location=...
     */
    public function detectLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location' => 'required|string',
        ]);

        $location = $this->service->detectLocation($validated['location']);

        return response()->json([
            'success' => true,
            'location' => $location,
        ]);
    }

    /**
     * Список пунктов выдачи (ПВЗ).
     * GET /api/public/delivery/yandex/pvz?geo_id=...&type=...
     */
    public function pvz(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'geo_id'           => 'nullable|integer',
            'type'             => 'nullable|string',
            'payment_method'   => 'nullable|string',
            'is_yandex_branded'=> 'nullable|boolean',
        ]);

        $filter = array_filter($validated, fn ($v) => $v !== null);
        $points = $this->service->getPickupPoints($filter);

        return response()->json([
            'success' => true,
            'points'  => $points,
        ]);
    }

    /**
     * Расчёт вариантов доставки (офферов) для витрины.
     *
     * POST /api/public/delivery/yandex/calculate
     *
     * Тело запроса:
     *   delivery_type  — 'pickup' | 'courier'
     *   pvz_id         — ID выбранного ПВЗ (обязателен при delivery_type=pickup)
     *   pvz_coords     — [lon, lat] координаты ПВЗ (при pickup, если pvz_id не известен)
     *   destination    — { address: string, coordinates: [lon, lat] } (для courier)
     *   recipient      — { name: string, phone: string }
     *   items          — [{ weight, size: {length,width,height}, quantity }]
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delivery_type'         => 'nullable|string|in:pickup,courier',
            'pvz_id'                => 'nullable|string',
            'pvz_coords'            => 'nullable|array',
            'pvz_coords.0'          => 'nullable|numeric',
            'pvz_coords.1'          => 'nullable|numeric',
            'destination'           => 'nullable|array',
            'destination.address'   => 'nullable|string',
            'destination.coordinates' => 'nullable|array',
            'recipient'             => 'nullable|array',
            'recipient.name'        => 'nullable|string',
            'recipient.phone'       => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.weight'        => 'required|numeric',
            'items.*.size'          => 'nullable|array',
            'items.*.quantity'      => 'nullable|integer|min:1',
        ]);

        $deliveryType = $validated['delivery_type'] ?? 'courier';
        $pvzId        = $validated['pvz_id'] ?? null;
        $pvzCoords    = $validated['pvz_coords'] ?? null;
        $destination  = $validated['destination'] ?? null;
        $recipient    = $validated['recipient'] ?? ['name' => 'Покупатель', 'phone' => '+70000000000'];
        $items        = $validated['items'];

        $offers = $this->service->calculateOffers(
            deliveryType: $deliveryType,
            items:        $items,
            pvzId:        $pvzId,
            pvzCoords:    $pvzCoords,
            destination:  $destination,
            recipient:    $recipient,
        );

        return response()->json([
            'success' => true,
            'offers'  => $offers,
        ]);
    }

    /**
     * Бронирование выбранного оффера.
     * POST /api/public/delivery/yandex/offers/confirm
     */
    public function confirmOffer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => 'required|string',
        ]);

        $result = $this->service->confirmOffer($validated['offer_id']);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
            ], 422);
        }

        return response()->json([
            'success'    => true,
            'request_id' => $result['request_id'],
            'result'     => $result['result'],
        ]);
    }

    /**
     * Информация о заявке и её статусе.
     * GET /api/public/delivery/yandex/request/{requestId}
     */
    public function requestInfo(string $requestId): JsonResponse
    {
        $result = $this->service->getRequestInfo($requestId);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'request' => $result['request'],
        ]);
    }

    /**
     * Геокодирование адреса.
     * GET /api/public/delivery/yandex/geocode?address=...
     */
    public function geocode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address' => 'required|string',
        ]);

        $coordinates = $this->service->geocode($validated['address']);

        if (!$coordinates) {
            return response()->json([
                'success' => false,
                'error'   => 'Could not geocode address',
            ], 404);
        }

        return response()->json([
            'success'     => true,
            'coordinates' => $coordinates,
        ]);
    }
}
