<?php

namespace App\Http\Controllers\Api\Public\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRestockSubscriptionRequest;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductRestockSubscription;
use App\Services\Client\GuestClientService;
use Illuminate\Http\JsonResponse;

class RestockSubscriptionController extends Controller
{
    /**
     * Создать подписку «Сообщить о поступлении».
     * POST /api/public/restock-subscriptions
     */
    public function store(
        StoreRestockSubscriptionRequest $request,
        GuestClientService $guestClientService,
    ): JsonResponse
    {
        $data = $request->validated();

        /** @var Product $product */
        $product = Product::findOrFail($data['product_id']);

        // Подписка имеет смысл только для опубликованного товара без остатка.
        if (!$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Товар недоступен.',
            ], 422);
        }

        if ((float)$product->stock_quantity > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Товар уже в наличии.',
            ], 422);
        }

        $email = mb_strtolower(trim($data['email']));

        // Привязка к клиенту: авторизованный клиент имеет приоритет. Для гостя
        // используем единый резолвер заказов: поиск по email/телефону и создание
        // клиента без ЛК (verified_at остаётся null), если совпадения нет.
        $client = null;
        $user = $request->user();
        if ($user instanceof Client) {
            $client = $user;
        } else {
            $client = $guestClientService->findOrCreateFromOrderData([
                'user' => ['email' => $email],
                'recipient' => [
                    'first_name' => $data['name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ],
            ]);
        }

        // Анти-дубль (#5): один и тот же email на один товар среди pending —
        // не плодим, отвечаем идемпотентно success.
        $existing = ProductRestockSubscription::query()
            ->forProduct($product->id)
            ->pending()
            ->where('email', $email)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Мы сообщим вам о поступлении.',
            ], 200);
        }

        ProductRestockSubscription::create([
            'product_id' => $product->id,
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'client_id' => $client?->id,
            'name' => $data['name'] ?? null,
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'status' => ProductRestockSubscription::STATUS_PENDING,
            'source' => 'site',
            'meta' => $data['meta'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Мы сообщим вам о поступлении.',
        ], 201);
    }
}
