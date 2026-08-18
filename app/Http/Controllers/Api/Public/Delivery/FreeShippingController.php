<?php

namespace App\Http\Controllers\Api\Public\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Delivery\FreeShipping\FreeShippingContext;
use App\Services\Delivery\FreeShippingService;
use App\Services\Order\OrderValidationService;
use App\Services\PromoCode\PromoCodeValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Публичная оценка бесплатной доставки для витрины
 * (см. docs/tasks/free-shipping.md).
 *
 * Только чтение: показывает, какие варианты доставки станут бесплатными и
 * сколько не хватает до ближайшего порога. Итоговая стоимость доставки всё
 * равно считается на бэкенде при создании заказа — цены из этого ответа
 * ни на что не влияют.
 */
class FreeShippingController extends Controller
{
    public function __construct(
        protected FreeShippingService $freeShippingService,
        protected OrderValidationService $orderValidationService,
        protected PromoCodeValidationService $promoCodeValidationService,
    ) {}

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',

            'promo_code' => 'nullable|string|max:64',
            'payment_method' => 'nullable|string|max:64',

            // id = 0 валиден (Россия) — поэтому min:0.
            'country_id' => 'nullable|integer|min:0',
            'region_id' => 'nullable|integer|min:0',
            'city_id' => 'nullable|integer|min:0',
            'country' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',

            'candidates' => 'nullable|array|max:30',
            'candidates.*.key' => 'nullable|string|max:120',
            'candidates.*.service' => 'nullable|string|max:32',
            'candidates.*.delivery_type' => 'nullable|string|max:32',
            'candidates.*.price' => 'nullable|numeric|min:0',
        ]);

        $client = Auth::guard('sanctum')->user();
        $client = $client instanceof Client ? $client : null;

        // Промокод учитываем только если он реально валиден для этого покупателя.
        $promoCode = null;
        if (! empty($validated['promo_code'])) {
            $result = $this->promoCodeValidationService->validate($validated['promo_code'], $client);
            if (! empty($result['success'])) {
                $promoCode = $result['promo_code'] ?? null;
            }
        }

        // Цены пересчитываем на бэкенде: сумма выкупа — после товарных скидок
        // и промокода (подарки акций в неё не входят: их цена 0).
        $items = $this->orderValidationService->priceItemsForEstimate(
            $validated['items'],
            $promoCode,
            $client
        );

        $context = new FreeShippingContext(
            items: $items,
            service: null,
            deliveryType: null,
            paymentMethod: $validated['payment_method'] ?? null,
            countryId: $this->intOrNull($validated['country_id'] ?? null),
            regionId: $this->intOrNull($validated['region_id'] ?? null),
            cityId: $this->intOrNull($validated['city_id'] ?? null),
            countryName: $validated['country'] ?? null,
            regionName: $validated['region'] ?? null,
            cityName: $validated['city'] ?? null,
        );

        $candidates = $this->freeShippingService->evaluateCandidates(
            $context,
            $validated['candidates'] ?? []
        );

        $qualifyingAmount = 0.0;
        foreach ($items as $item) {
            $qualifyingAmount += $item['quantity'] * $item['price'];
        }

        return response()->json([
            'success' => true,
            'qualifying_amount' => round($qualifyingAmount, 2),
            'candidates' => $candidates,
            'progress' => $this->freeShippingService->progress($context),
        ]);
    }

    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
