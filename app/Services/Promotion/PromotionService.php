<?php

namespace App\Services\Promotion;

use App\Models\Order;
use App\Models\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionService
{
    /**
     * Найти применимые акции для корзины
     */
    public function findApplicablePromotions(array $cartItems, float $cartTotal): Collection
    {
        // Получаем активные акции
        $activePromotions = Promotion::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('max_uses')
                    ->orWhereRaw('times_used < max_uses');
            })
            ->with(['triggerProducts', 'giftProducts.images'])
            ->orderBy('priority', 'desc')
            ->get();

        $applicable = collect();

        foreach ($activePromotions as $promotion) {
            if ($this->isPromotionApplicable($promotion, $cartItems, $cartTotal)) {
                $applicable->push($promotion);
            }
        }

        return $applicable;
    }

    /**
     * Свести список применимых акций к итоговому набору по правилам стекирования.
     *
     * Семантика флага is_stackable:
     *   - Если есть хотя бы одна стекируемая применимая акция — применяются ВСЕ
     *     стекируемые; невзаимные (is_stackable=false) при этом пропускаются
     *     (они «эксклюзивные» и не комбинируются).
     *   - Если стекируемых акций нет — берётся одна с наибольшим priority
     *     (полностью совместимо со старым поведением «одна акция на заказ»).
     *
     * На вход ожидается коллекция, отсортированная по priority desc
     * (как возвращает findApplicablePromotions).
     */
    public function resolveApplicablePromotions(Collection $applicable): Collection
    {
        if ($applicable->isEmpty()) {
            return collect();
        }

        $stackable = $applicable->filter(fn (Promotion $p) => $p->isStackable())->values();

        if ($stackable->isNotEmpty()) {
            return $stackable;
        }

        // Стекируемых нет — старое поведение: одна акция по приоритету.
        return $applicable->take(1)->values();
    }

    /**
     * Найти и сразу свести применимые акции к итоговому набору.
     */
    public function findResolvedPromotions(array $cartItems, float $cartTotal): Collection
    {
        return $this->resolveApplicablePromotions(
            $this->findApplicablePromotions($cartItems, $cartTotal)
        );
    }

    /**
     * Проверить, применима ли акция к корзине
     */
    protected function isPromotionApplicable(Promotion $promotion, array $cartItems, float $cartTotal): bool
    {
        // 1. Проверка минимальной суммы покупки
        if ($cartTotal < $promotion->min_purchase_amount) {
            return false;
        }

        // 2. Проверка наличия товаров-триггеров в корзине
        $triggerProductIds = $promotion->triggerProducts->pluck('id')->toArray();

        if (empty($triggerProductIds)) {
            // Если нет товаров-триггеров, акция применима ко всем
            return true;
        }

        $cartProductIds = collect($cartItems)->pluck('product_id')->toArray();

        // Проверяем, есть ли хотя бы один товар-триггер в корзине
        return ! empty(array_intersect($triggerProductIds, $cartProductIds));
    }

    /**
     * Применить акцию к заказу
     *
     * @param  Order  $order
     * @param  Promotion  $promotion
     * @param  int  $giftProductId  ID товара-подарка
     * @param  bool  $useDiscountInstead  Если true — клиент выбрал скидку/промокод вместо подарка
     * @param  int|null  $giftVariantId  ID конкретного варианта подарка (размер/цвет), если у товара есть варианты
     */
    public function applyPromotionToOrder(
        Order $order,
        Promotion $promotion,
        int $giftProductId,
        bool $useDiscountInstead = false,
        ?int $giftVariantId = null
    ): void {
        // ВНИМАНИЕ: этот метод вызывается из OrderController::store, который уже
        // обёрнут в DB::beginTransaction(). Открывать здесь ещё одну транзакцию
        // НЕЛЬЗЯ — это создаст вложенный SAVEPOINT, и если бросить
        // ValidationException, outer rollback не сможет найти savepoint
        // (ошибка "SAVEPOINT trans2 does not exist"). lockForUpdate ниже
        // работает корректно и в рамках уже открытой outer-транзакции.

        try {
            // Обновляем заказ
            $order->update([
                'promotion_id' => $promotion->id,
            ]);

            // Подарок акции из связки (нужен и для записи в usages — для gift_quantity)
            $giftProduct = $promotion->giftProducts()
                ->where('product_id', $giftProductId)
                ->first();

            $quantity = $giftProduct?->pivot->quantity ?? 1;

            if (! $useDiscountInstead && $giftProduct) {
                // Если у товара есть варианты — подарок добавляем строго на конкретный variant.
                // Stock-проверка и списание делаются АТОМАРНО, под pessimistic lock,
                // чтобы исключить гонку, при которой два параллельных заказа могут
                // забрать один и тот же последний экземпляр подарка (см. BUG-1).
                $variant = null;
                if ($giftVariantId) {
                    $variant = \App\Models\ProductVariant::where('id', $giftVariantId)
                        ->where('product_id', $giftProductId)
                        ->lockForUpdate()
                        ->first();

                    if (! $variant) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'gift_product_variant_id' => ['Выбранный размер недоступен.'],
                        ]);
                    }

                    $stock = $variant->stock_quantity;
                    if ($stock !== null && $stock !== '' && (float) $stock < $quantity) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'gift_product_variant_id' => ['Выбранного размера нет в наличии.'],
                        ]);
                    }
                } else {
                    // Подарок-товар без вариантов — блокируем строку самого товара и проверяем остаток.
                    // Раньше тут не было проверки stock на уровне service (см. BUG-2):
                    // CreateOrderRequest проверял остаток только у вариантов, и товар-подарок
                    // без вариантов мог уйти с stock_quantity → -1.
                    $productLock = \App\Models\Product::where('id', $giftProductId)
                        ->lockForUpdate()
                        ->first();

                    if (! $productLock) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'gift_product_id' => ['Подарок недоступен.'],
                        ]);
                    }

                    $stock = $productLock->stock_quantity;
                    if ($stock !== null && $stock !== '' && (float) $stock < $quantity) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'gift_product_id' => ['Подарка нет в наличии.'],
                        ]);
                    }
                }

                $order->items()->create([
                    'product_id' => $giftProductId,
                    'product_variant_id' => $variant?->id,
                    // Денормализуем цвет в OrderItem.color_id (как и у обычных позиций),
                    // чтобы в админке/сборке заказа было видно, какого цвета подарок.
                    // Источник правды — product_variants.color_id выбранного варианта.
                    'color_id' => $variant?->color_id,
                    'quantity' => $quantity,
                    'price' => 0.00, // Подарок бесплатный
                    'discount' => 0.00,
                    'is_gift' => true,
                    'promotion_id' => $promotion->id,
                ]);

                // Списываем остаток с того, что отгружаем (variant если есть, иначе сам товар).
                // Stock уже проверен под lockForUpdate выше — decrement безопасен.
                if ($variant) {
                    $variant->decrement('stock_quantity', $quantity);
                } else {
                    \App\Models\Product::where('id', $giftProductId)
                        ->decrement('stock_quantity', $quantity);
                }
            }

            // Создаем запись об использовании
            $promotion->usages()->create([
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'gift_product_id' => $giftProductId,
                'gift_product_variant_id' => $useDiscountInstead ? null : $giftVariantId,
                'gift_quantity' => $quantity,
                'used_discount_instead' => $useDiscountInstead,
            ]);

            // Увеличиваем счетчик использований
            $promotion->increment('times_used');

            Log::info('Promotion applied to order', [
                'promotion_id' => $promotion->id,
                'order_id' => $order->id,
                'gift_product_id' => $giftProductId,
                'gift_variant_id' => $giftVariantId,
                'used_discount_instead' => $useDiscountInstead,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to apply promotion to order', [
                'promotion_id' => $promotion->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // Пробрасываем дальше — outer DB::beginTransaction() в OrderController
            // сам выполнит rollback. ValidationException попадёт в стандартный
            // Laravel-обработчик и вернёт клиенту 422.
            throw $e;
        }
    }

    /**
     * Применить несколько акций к заказу (накопительные/стекируемые подарки).
     *
     * Принимает массив выборов вида:
     *   [
     *     ['promotion_id' => int, 'gift_product_id' => ?int,
     *      'gift_product_variant_id' => ?int, 'use_discount_instead' => bool],
     *     ...
     *   ]
     *
     * Каждая акция применяется отдельной записью (свой подарок + своя запись в
     * promotion_usages + инкремент times_used). Вызывается внутри outer-транзакции
     * контроллера заказа — собственную транзакцию НЕ открываем (см. коммент в
     * applyPromotionToOrder).
     *
     * Дубли подарков разрешены: один и тот же товар-подарок от двух акций даёт
     * две отдельные подарочные позиции (каждая со своим promotion_id).
     */
    public function applyPromotionsToOrder(Order $order, array $selections): void
    {
        foreach ($selections as $selection) {
            $promotionId = (int) ($selection['promotion_id'] ?? 0);
            if (! $promotionId) {
                continue;
            }

            $promotion = Promotion::find($promotionId);

            if (! $promotion) {
                Log::warning('Promotion not found (multi)', ['promotion_id' => $promotionId]);

                continue;
            }

            if (! $promotion->isActive()) {
                Log::warning('Promotion is not active (multi)', ['promotion_id' => $promotionId]);

                continue;
            }

            $useDiscountInstead = (bool) ($selection['use_discount_instead'] ?? false);
            $giftProductId = $selection['gift_product_id'] ?? null;

            // Без подарка и без «скидки вместо подарка» применять нечего.
            if (! $useDiscountInstead && empty($giftProductId)) {
                continue;
            }

            $this->applyPromotionToOrder(
                $order,
                $promotion,
                (int) ($giftProductId ?? 0),
                $useDiscountInstead,
                isset($selection['gift_product_variant_id'])
                    ? (int) $selection['gift_product_variant_id']
                    : null
            );
        }
    }

    /**
     * Проверить, можно ли использовать промокод с акцией
     */
    public function canUsePromoCodeWithPromotion(?Promotion $promotion): bool
    {
        if (! $promotion) {
            return true; // Нет акции - можно использовать промокод
        }

        return $promotion->allowsPromoCodes();
    }

    /**
     * Отменить применение акций к заказу.
     *
     * Поддерживает несколько акций на одном заказе (накопительные подарки):
     * откатывает times_used по каждой затронутой акции, удаляет все записи
     * promotion_usages этого заказа и все подарочные позиции.
     */
    public function cancelPromotionFromOrder(Order $order): void
    {
        DB::beginTransaction();

        try {
            // Все акции, применённые к заказу (источник правды — usages).
            $usages = \App\Models\PromotionUsage::where('order_id', $order->id)->get();

            // Откатываем счётчик использований по каждой акции.
            $promotionIds = $usages->pluck('promotion_id')->filter()->unique();
            foreach ($promotionIds as $promotionId) {
                $count = $usages->where('promotion_id', $promotionId)->count();
                $promotion = Promotion::find($promotionId);
                if ($promotion && $count > 0) {
                    // Не уводим счётчик в минус.
                    $newValue = max(0, (int) $promotion->times_used - $count);
                    $promotion->update(['times_used' => $newValue]);
                }
            }

            // Удаляем все записи об использовании заказа.
            \App\Models\PromotionUsage::where('order_id', $order->id)->delete();

            // Удаляем все подарочные позиции заказа.
            // ВАЖНО: возврат остатка на склад сюда НЕ добавляем — он уже делается
            // в OrderCreationService::cancelOrder() для всех позиций заказа, включая
            // подарочные. Дублирование привело бы к двойному инкременту stock_quantity.
            $order->items()
                ->where('is_gift', true)
                ->delete();

            // Сбрасываем «основную» акцию заказа (back-compat поле).
            if ($order->promotion_id) {
                $order->update(['promotion_id' => null]);
            }

            DB::commit();

            Log::info('Promotions cancelled from order', [
                'order_id' => $order->id,
                'promotion_ids' => $promotionIds->values()->all(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to cancel promotions from order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Получить статистику по акции
     */
    public function getPromotionStats(Promotion $promotion): array
    {
        // Под стекированием orders.promotion_id хранит лишь одну акцию, поэтому
        // считаем заказы/выручку через promotion_usages (по distinct order_id),
        // а не через relation orders() — иначе статистика занижается.
        $orderIds = $promotion->usages()
            ->whereNotNull('order_id')
            ->distinct()
            ->pluck('order_id');

        return [
            'total_uses' => $promotion->times_used,
            'remaining_uses' => $promotion->max_uses ? ($promotion->max_uses - $promotion->times_used) : null,
            'total_orders' => $orderIds->count(),
            'total_revenue' => $orderIds->isEmpty()
                ? 0
                : Order::whereIn('id', $orderIds)->sum('total_amount'),
            'unique_clients' => $promotion->usages()->distinct('client_id')->count('client_id'),
            'gift_chosen_count' => $promotion->usages()->where('used_discount_instead', false)->count(),
            'discount_chosen_count' => $promotion->usages()->where('used_discount_instead', true)->count(),
        ];
    }
}
