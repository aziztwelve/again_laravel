<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер NDD Express Delivery API.
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
        if (isset($filter['geo_id'])) $filter['geo_id'] = (int) $filter['geo_id'];
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
            'items.*.weight'        => 'nullable|numeric|min:0.001',
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
     * Бронирование оффера больше не применяется в NDD Express: заявка создаётся
     * только после оплаты заказа через claims/create.
     * POST /api/public/delivery/yandex/offers/confirm
     */
    public function confirmOffer(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'Офферы NDD Express не бронируются до оплаты заказа.'], 410);
    }

    /**
     * Информация о заявке и её статусе.
     * GET /api/public/delivery/yandex/request/{requestId}
     */
    public function requestInfo(string $requestId): JsonResponse
    {
        $result = $this->service->getClaimInfo($requestId);

        if (!$result['successful']) {
            return response()->json([
                'success' => false,
                'error'   => $result['data'],
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'request' => $result['data'],
        ]);
    }

    public function tariffs(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'tariffs' => \App\Models\YandexTariff::query()->where('is_active', true)->orderBy('sort')->get(['code', 'title', 'taxi_class']),
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
