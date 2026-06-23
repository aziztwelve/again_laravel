<?php

namespace App\Http\Controllers\Api\Public\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\GiftCard\GiftCardService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Services\Order\OrderCreationService;
use App\Services\Order\OrderValidationService;
use App\Services\PromoCode\PromoCodeValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Публичный чекаут: оформление заказа без авторизации (гость) и для
 * авторизованного клиента (через опциональный Bearer-токен).
 *
 * Логика повторяет OrderController::store, но:
 *  - не падает на 401 при отсутствии auth;
 *  - запрещает админам пользоваться этим endpoint'ом (для них есть /api/orders);
 *  - подставляет client_id = NULL при гостевом заказе.
 */
class PublicCheckoutController extends Controller
{
    public function __construct(
        protected OrderValidationService $orderValidationService,
        protected OrderCreationService $orderCreationService,
        protected PromoCodeValidationService $promoValidationService,
        protected GiftCardService $giftCardService,
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        // Маршрут публичный (без auth-middleware), но если клиент прислал
        // валидный Bearer-токен — резолвим его через sanctum-гард и привязываем
        // заказ к этому клиенту. Если токена нет или он недействителен —
        // $authUser останется NULL и заказ оформится как гостевой.
        $authUser = Auth::guard('sanctum')->user();

        // Админам сюда нельзя — для них есть /api/orders (там они выбирают
        // client_id и проходят другие проверки прав). Иначе админ-токен
        // в публичном endpoint мог бы случайно создать заказ без client_id.
        if ($authUser instanceof User) {
            return $this->errorResponse(
                'Этот endpoint предназначен для покупателей. Используйте /api/orders.',
                403
            );
        }

        DB::beginTransaction();

        try {
            $validated = $request->validated();

            /** @var Client|null $orderClient */
            $orderClient = $authUser instanceof Client ? $authUser : null;

            // Для гостя client_id всегда NULL — не позволяем фронту его подделать
            // (даже если в payload пришло client_id, его игнорируем).
            if ($orderClient === null) {
                unset($validated['client_id']);
            } else {
                $validated['client_id'] = $orderClient->id;
            }

            // Собираем тех-метаданные (IP/UA) — особенно важно для гостевых заказов
            // как сигнал анти-фрода и для разруливания спорных ситуаций.
            $validated['ip_address'] = $request->ip();
            $validated['user_agent'] = $request->userAgent();

            // UTM-атрибуция: метка пришла в куке от редирект-трекера /go/{slug}
            // (см. docs/tasks/utm-tracking.md, решение #2).
            $validated['utm_link_id'] = $this->resolveUtmLinkId($request);

            // 1. Промокод
            $promoCode = null;
            if (! empty($validated['promo_code'])) {
                $promoResult = $this->promoValidationService->validate(
                    $validated['promo_code'],
                    $orderClient
                );

                if (! $promoResult['success']) {
                    return $this->errorResponse(
                        $promoResult['message'],
                        422,
                        [
                            'code' => $promoResult['code'] ?? null,
                            'details' => $promoResult,
                        ]
                    );
                }

                $promoCode = $promoResult['promo_code'];
            }

            // 2. Подарочная карта (применение по коду)
            $giftCard = null;
            if (! empty($validated['gift_card_code'])) {
                $giftCardValidation = $this->giftCardService->validate($validated['gift_card_code']);

                if (! $giftCardValidation['valid']) {
                    return $this->errorResponse($giftCardValidation['message'], 422);
                }

                $giftCard = $giftCardValidation['gift_card'];
            }

            // 3. Валидация позиций
            $promotionId = $validated['promotion_id'] ?? null;
            $itemsValidation = $this->orderValidationService->validateOrderItems(
                $validated['items'],
                $promoCode,
                $promotionId
            );

            if (! $itemsValidation['valid']) {
                DB::rollBack();

                return $this->validationErrorResponse($itemsValidation['errors']);
            }

            // 4. Создаём заказ (client_id берётся из $validated['client_id'] или NULL)
            $order = $this->orderCreationService->createOrder(
                $validated,
                $orderClient?->id
            );

            // 5. Позиции
            $totals = $this->orderCreationService->createOrderItems(
                $order,
                $itemsValidation['validated_items']
            );

            // 6. Применяем промокод
            if ($promoCode) {
                $this->orderCreationService->applyPromoCodeToOrder(
                    $order,
                    $promoCode,
                    $totals['order_total'],
                    $totals['total_discount'],
                    $totals['total_promo_discount']
                );
            } else {
                $this->orderCreationService->updateOrderTotals($order, $totals);
            }

            // 7. Акция
            if ($promotionId && ! empty($validated['gift_product_id'])) {
                $useDiscountInstead = $validated['use_discount_instead'] ?? false;
                $this->orderCreationService->applyPromotionToOrder(
                    $order,
                    $promotionId,
                    $validated['gift_product_id'],
                    $useDiscountInstead,
                    $validated['gift_product_variant_id'] ?? null
                );
            }

            // 8. Подарочная карта как способ оплаты
            if ($giftCard) {
                $this->orderCreationService->applyGiftCardToOrder(
                    $order,
                    $giftCard,
                    $order->total_amount
                );
            }

            // 9. Подарочный сертификат как товар → создаём карту
            $containsGiftCard = $this->orderCreationService->containsGiftCardProduct($validated['items']);
            if ($containsGiftCard) {
                foreach ($validated['items'] as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product && $product->name === 'Подарочный сертификат') {
                        $nominal = $this->orderCreationService->extractGiftCardNominal($item);
                        if ($nominal) {
                            $giftCardData = $request->input('gift_card_data', []);
                            $giftCardCreated = $this->giftCardService->createFromOrder(
                                $order,
                                $giftCardData,
                                $nominal
                            );
                            $this->giftCardService->scheduleDelivery($giftCardCreated, $giftCardData);
                        }
                    }
                }
            }

            // 10. Уведомления (поддерживает гостя через order->email)
            $this->sendNotifications($order, $orderClient);

            DB::commit();

            $order->load([
                'items.product',
                'items.variant.optionValues.option',
                'items.variant.table_color',
                'promoCode',
                'giftCard',
                'address',
            ]);

            return $this->successResponse(
                'Заказ успешно создан',
                [
                    'order' => $order,
                    'summary' => $this->orderCreationService->getOrderSummary($order),
                    'contains_gift_card_product' => $containsGiftCard,
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Public order creation failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'is_guest' => ! ($authUser instanceof Client),
            ]);

            return $this->errorResponse(
                'Ошибка при создании заказа. Пожалуйста, попробуйте позже.',
                500,
                [
                    'error_details' => config('app.debug') ? $e->getMessage() : null,
                ]
            );
        }
    }

    /**
     * Уведомления о заказе. Для гостя email берётся из самого заказа
     * (order->email). Telegram — только для зарегистрированных клиентов
     * с привязанным telegram_user_id.
     */
    private function sendNotifications(Order $order, ?Client $client): void
    {
        try {
            $message = "Ваш заказ #{$order->id} принят! Сумма: {$order->total_amount} руб.";

            $email = $client?->email ?? $order->email;
            if ($email) {
                SendNotificationJob::dispatch('email', $email, $message, [
                    'order_id' => $order->id,
                    'view_token' => $order->view_token,
                ]);
            }

            if ($client?->profile?->telegram_user_id) {
                SendNotificationJob::dispatch(
                    'telegram',
                    $client->profile->telegram_user_id,
                    $message,
                    ['order_id' => $order->id]
                );
            }

            Log::info('Order notifications queued', [
                'order_id' => $order->id,
                'is_guest' => $client === null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to queue notifications', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function successResponse(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            ...$data,
        ], $status);
    }

    private function errorResponse(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            ...$extra,
        ], $status);
    }

    private function validationErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Обнаружены ошибки при проверке товаров в корзине',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Достаёт id UTM-метки из куки utm_link_id (её ставит редирект-трекер
     * /go/{slug}). Возвращает NULL, если куки нет или метка не существует.
     */
    private function resolveUtmLinkId(Request $request): ?int
    {
        $cookieValue = $request->cookie('utm_link_id');

        if (! is_numeric($cookieValue)) {
            return null;
        }

        $linkId = (int) $cookieValue;

        return \App\Models\UtmLink::whereKey($linkId)->exists() ? $linkId : null;
    }
}
