<?php

namespace App\Services\Order;

use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Управление множественными ручными скидками на заказе.
 *
 * Контракт цен по позиции (order_items):
 *  - item.price    — финальная цена за штуку (после auto + всех ручных + промо)
 *  - item.discount — суммарная скидка за штуку (auto + все ручные + промо)
 *  - "оригинальная" цена при пересчёте берётся как item.price + item.discount,
 *    что устойчиво к ручным правкам цены через UI позиций.
 *
 * Порядок применения скидок:
 *  1. Восстановить оригиналы по позициям (price = original; discount = 0).
 *  2. Авто-скидка (Product↔Discount): {@see OrderUpdateService::reapplyAutoDiscounts}.
 *  3. Все ручные скидки из pivot order_applied_discounts стекаются СВЕРХУ авто:
 *     каждая % считается от оригинальной цены (аддитивно: 10% + 5% = 15%);
 *     fixed — фиксированная сумма за штуку, ограниченная остатком.
 *     Сумма всех скидок per unit ограничена оригинальной ценой (не уйдём в минус).
 *  4. Промокод (если применён) поверх — inline через {@see PromoCode::calculateFinalPrice},
 *     с уважением `discount_behavior`:
 *      - replace: промо считается от оригинальной цены и заменяет auto+manual
 *        (на тех позициях, где промо применился; pivot.applied_amount этих
 *        ручных скидок пересчитывается соответствующим образом);
 *      - stack:   промо считается от текущей цены (после auto+manual);
 *      - skip:    промо не применяется к позициям с auto/manual скидкой.
 *     Times_used и usages промокода здесь НЕ трогаются — это делается
 *     только при первичной привязке промокода ({@see OrderUpdateService}).
 *
 * Pivot.applied_amount пересчитывается на каждом recalculate и хранит
 * суммарную экономию по этой ручной скидке в этом заказе (для UI/истории).
 */
class OrderDiscountService
{
    public function __construct(
        protected OrderUpdateService $orderUpdateService,
        protected OrderValidationService $orderValidationService,
    ) {}

    /**
     * Сводка по auto-скидкам, реально применённым к позициям заказа.
     *
     * Возвращает массив элементов:
     *   [
     *     'id' => int,            // Discount::id
     *     'name' => string,
     *     'type' => 'fixed'|'percentage',
     *     'value' => float,
     *     'discount_type' => 'all'|'specific'|'category',
     *     'applied_amount' => float, // суммарная auto-экономия по этому заказу
     *   ]
     *
     * Auto-скидку формирует {@see OrderValidationService::applyDiscountToProduct}:
     * она мутирует $model->discount_id и $model->total_discount. Мы прогоняем
     * её повторно по позициям, чтобы получить чистую auto-часть (без manual/promo),
     * и группируем по discount_id.
     */
    public function getAutoDiscountsSummary(Order $order): array
    {
        $perDiscount = []; // discount_id => [name, type, value, discount_type, applied_amount]

        // Если на заказ навешен промокод с поведением "replace" — авто-скидка
        // на позициях, к которым промокод применился, не действует (её перекрывает
        // промо). Не учитываем такие позиции в applied_amount.
        $promoCode = $order->promo_code_id ? PromoCode::find($order->promo_code_id) : null;
        $promoReplaces = $promoCode && $promoCode->discount_behavior === 'replace';

        foreach ($order->items()->get() as $item) {
            if ($item->is_gift || ! $item->product_id) {
                continue;
            }

            $model = $item->product_variant_id
                ? ProductVariant::find($item->product_variant_id)
                : Product::find($item->product_id);
            if (! $model) {
                continue;
            }

            $this->orderValidationService->applyDiscountToProduct($model);
            $autoPerUnit = (float) ($model->total_discount ?? 0);
            $discountId  = $model->discount_id ?? null;

            if ($autoPerUnit < 0.01 || ! $discountId) {
                continue;
            }

            if (! isset($perDiscount[$discountId])) {
                $d = Discount::find($discountId);
                if (! $d) {
                    continue;
                }
                $perDiscount[$discountId] = [
                    'id'             => $d->id,
                    'name'           => $d->name,
                    'type'           => $d->type,
                    'value'          => (float) $d->value,
                    'discount_type'  => $d->discount_type,
                    'applied_amount' => 0.0,
                ];
            }

            // Промокод с replace перекрывает auto на этой позиции:
            // скидка остаётся в списке (фронт покажет её как «не применена»),
            // но в applied_amount её вклад не идёт.
            if ($promoReplaces && $promoCode->isApplicableToProduct($item->product_id)) {
                continue;
            }

            $perDiscount[$discountId]['applied_amount'] += $autoPerUnit * (int) $item->quantity;
        }

        return array_map(function ($row) {
            $row['applied_amount'] = round($row['applied_amount'], 2);
            return $row;
        }, array_values($perDiscount));
    }

    /**
     * Проверить, применима ли скидка к заказу (хотя бы к одной позиции),
     * и не является ли она дублем уже применённой auto-скидки.
     *
     * Возвращает:
     *  - null  — применима, attach можно делать;
     *  - string — текст причины, по которой attach имеет смысл отклонить.
     *
     * Используется контроллером перед attach(), чтобы не плодить «висящие»
     * записи в pivot, которые в реальности дают 0 ₽ (например, скидка
     * с discount_type='specific' без привязанных товаров/вариантов).
     */
    public function checkApplicabilityReason(Order $order, Discount $discount): ?string
    {
        $items = $order->items()->with('product.categories')->get()
            ->filter(fn ($item) => ! $item->is_gift && $item->product_id);

        if ($items->isEmpty()) {
            return 'В заказе нет товаров, к которым можно применить скидку';
        }

        // 1. Применима ли скидка хотя бы к одной позиции (по discount_type).
        $appliesAny = $items->contains(fn ($item) => $this->discountAppliesToItem($discount, $item));
        if (! $appliesAny) {
            return match ($discount->discount_type) {
                'specific' => "Скидка «{$discount->name}» не привязана ни к одному из товаров заказа",
                'category' => "Скидка «{$discount->name}» не относится ни к одной категории товаров заказа",
                default    => "Скидка «{$discount->name}» неприменима к товарам заказа",
            };
        }

        // 2. Если на КАЖДОЙ применимой позиции эта же скидка уже работает как
        //    auto (Product↔Discount), стекать её ещё и ручной — бессмысленно
        //    (anti-dup в applyManualDiscountsStacked всё равно выдаст 0 ₽).
        $autoMap = $this->mapItemAutoDiscount($items);
        $applicableItems = $items->filter(
            fn ($item) => $this->discountAppliesToItem($discount, $item)
        );
        $allAlreadyAuto = $applicableItems->every(
            fn ($item) => ($autoMap[$item->id] ?? null) === (int) $discount->id
        );
        if ($allAlreadyAuto) {
            return "Скидка «{$discount->name}» уже применяется автоматически к товарам заказа";
        }

        return null;
    }

    /**
     * Добавить ручную скидку к заказу. Если уже есть — no-op.
     * Возвращает true если скидка добавлена и заказ пересчитан.
     */
    public function attach(Order $order, Discount $discount): bool
    {
        if (! $discount->isValid()) {
            return false;
        }

        // Если эта скидка уже применена — ничего не делаем.
        if ($order->appliedDiscounts()->where('discounts.id', $discount->id)->exists()) {
            return false;
        }

        $nextPosition = (int) ($order->appliedDiscounts()->max('order_applied_discounts.position') ?? -1) + 1;

        DB::transaction(function () use ($order, $discount, $nextPosition) {
            $order->appliedDiscounts()->attach($discount->id, [
                'applied_amount' => 0,
                'position'       => $nextPosition,
            ]);

            // Поддерживаем legacy-колонку: пишем туда первую (нижнюю по position) скидку.
            if ($order->applied_discount_id === null) {
                $order->applied_discount_id = $discount->id;
                $order->save();
            }

            $this->recalculate($order->fresh());
        });

        return true;
    }

    /**
     * Снять одну ручную скидку с заказа.
     */
    public function detach(Order $order, int $discountId): bool
    {
        if (! $order->appliedDiscounts()->where('discounts.id', $discountId)->exists()) {
            return false;
        }

        DB::transaction(function () use ($order, $discountId) {
            $order->appliedDiscounts()->detach($discountId);

            // Если сняли legacy-скидку — переставим на первую из оставшихся (или null).
            if ((int) $order->applied_discount_id === $discountId) {
                $first = $order->appliedDiscounts()
                    ->orderBy('order_applied_discounts.position')
                    ->first();
                $order->applied_discount_id = $first?->id;
                $order->save();
            }

            $this->recalculate($order->fresh());
        });

        return true;
    }

    /**
     * Снять все ручные скидки с заказа.
     */
    public function detachAll(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->appliedDiscounts()->detach();
            $order->applied_discount_id = null;
            $order->save();

            $this->recalculate($order->fresh());
        });
    }

    /**
     * Полный пересчёт цен позиций заказа: auto → ручные (stack) → промо.
     *
     * Идемпотентен и безопасен для повторного вызова: каждый раз восстанавливаем
     * оригинальные цены и проигрываем все скидки заново.
     */
    public function recalculate(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // 1. Сброс позиций до оригинальной цены.
            //    "Оригинал" берём как item.price + item.discount — это устойчиво
            //    к ручным правкам цены через UI и совпадает с конвенцией
            //    существующих контроллеров.
            foreach ($order->items()->get() as $item) {
                if ($item->is_gift || ! $item->product_id) {
                    continue;
                }
                $original = (float) $item->price + (float) ($item->discount ?? 0);
                $item->update([
                    'price'    => round($original, 2),
                    'discount' => 0,
                ]);
            }

            // Сбрасываем агрегаты — они будут пересобраны.
            $order->total_items_discount = 0;
            $order->total_promo_discount = 0;
            $order->discount_amount      = 0;
            $order->save();

            // 2. Авто-скидки от привязки Product↔Discount.
            //    После этого item.price = price_after_auto, item.discount = auto_per_unit,
            //    а order.total_items_discount = чистая сумма auto-скидок по заказу.
            $this->orderUpdateService->reapplyAutoDiscounts($order->fresh());
            $order->refresh();
            $autoTotal = (float) $order->total_items_discount;

            // 3. Ручные скидки из pivot — стекаются поверх авто, % от оригинала.
            //    applyManualDiscountsStacked возвращает суммарную экономию по
            //    ручным скидкам и обновляет pivot.applied_amount.
            $manualTotal = $this->applyManualDiscountsStacked($order);

            // total_items_discount = auto + все ручные.
            $order->total_items_discount = round($autoTotal + $manualTotal, 2);
            $order->discount_amount = round(
                (float) $order->total_items_discount + (float) ($order->total_promo_discount ?? 0),
                2
            );
            $order->save();

            // 4. Промокод поверх (если был привязан).
            //    Inline через PromoCode::calculateFinalPrice с уважением discount_behavior.
            //    Не используем reapplyPromoCode/applyPromoCodeChange, потому что они
            //    сбрасывают item.discount = 0 и инкрементят times_used, что нам не нужно.
            if ($order->promo_code_id) {
                $this->applyPromoInline($order->fresh());
                $order->refresh();
            }
            $order->updateTotalAmount();
        });
    }

    /**
     * Применить уже привязанный к заказу промокод к ценам позиций.
     *
     * Не трогает promo_code->times_used и usages — это делается только
     * при первичной привязке промокода.
     *
     * Предусловия: позиции уже содержат auto + ручные скидки в item.discount.
     * Постусловия:
     *   - item.price       = финальная цена после auto+manual+promo;
     *   - item.discount    = суммарная скидка на штуку (auto+manual+promo;
     *                        при replace ручные/авто заменяются на promo);
     *   - order.total_promo_discount = сумма promo-части по заказу;
     *   - order.total_items_discount = сумма auto+manual без promo;
     *   - pivot.applied_amount ручных скидок пересчитан с учётом replace.
     */
    protected function applyPromoInline(Order $order): void
    {
        $promoCode = PromoCode::find($order->promo_code_id);
        if (! $promoCode) {
            return;
        }

        $totalPromoDiscount = 0.0;
        $totalItemsDiscount = 0.0;

        foreach ($order->items()->get() as $item) {
            if ($item->is_gift || ! $item->product_id) {
                continue;
            }

            $original     = (float) $item->price + (float) ($item->discount ?? 0);
            $currentPrice = (float) $item->price;       // после auto+manual
            $autoManualPerUnit = (float) ($item->discount ?? 0);
            $hasDiscount  = $autoManualPerUnit > 0.001;
            $qty          = (int) $item->quantity;

            // promo не применяется к этой позиции?
            $applicable = $promoCode->isApplicableToProduct($item->product_id)
                && $promoCode->canApplyToProductWithDiscount($hasDiscount);

            if (! $applicable) {
                // promo пропустил позицию (skip-поведение или товар не в applies_to)
                // → items-часть = auto+manual, promo = 0. item.price/discount не меняем.
                $totalItemsDiscount += $autoManualPerUnit * $qty;
                continue;
            }

            $result = $promoCode->calculateFinalPrice($original, $currentPrice, $hasDiscount);
            $finalPrice         = (float) $result['final_price'];
            $promoDiscountUnit  = (float) $result['promo_discount'];
            $totalDiscountUnit  = round($original - $finalPrice, 2);

            // items-часть на этой позиции зависит от behavior:
            //  - replace: auto+manual «съедены» промокодом, items-часть = 0;
            //  - stack:   items-часть = auto+manual (сохраняются);
            //  - skip:    обработано в ветке !$applicable.
            $itemsPartUnit = match ($promoCode->discount_behavior) {
                'replace' => 0.0,
                default   => $autoManualPerUnit,
            };

            $item->update([
                'price'    => round($finalPrice, 2),
                'discount' => $totalDiscountUnit,
            ]);

            $totalPromoDiscount += $promoDiscountUnit * $qty;
            $totalItemsDiscount += $itemsPartUnit * $qty;
        }

        // Пересчёт pivot.applied_amount при replace: ручные скидки, чьи позиции
        // были «перекрыты» промокодом, теряют свой вклад. Чтобы не дублировать
        // логику применимости, пересчитаем pivot строго из items, проиграв
        // ручные скидки на оригиналах позиций, к которым промокод НЕ применился.
        if ($promoCode->discount_behavior === 'replace') {
            $perDiscountManualTotals = $this->computeManualTotalsForNonPromoItems($order, $promoCode);
            foreach ($perDiscountManualTotals as $did => $amount) {
                $order->appliedDiscounts()->updateExistingPivot($did, [
                    'applied_amount' => round($amount, 2),
                ]);
            }
        }

        $order->total_promo_discount = round($totalPromoDiscount, 2);
        $order->total_items_discount = round($totalItemsDiscount, 2);
        $order->discount_amount = round($totalItemsDiscount + $totalPromoDiscount, 2);
        $order->save();
    }

    /**
     * Подсчитать суммарный вклад каждой ручной скидки ТОЛЬКО на позициях,
     * к которым промокод НЕ применился (для behavior=replace).
     *
     * Возвращает discount_id => applied_amount.
     */
    protected function computeManualTotalsForNonPromoItems(Order $order, PromoCode $promoCode): array
    {
        $manualDiscounts = $order->appliedDiscounts()
            ->with(['products:id', 'productVariants:id', 'categories:id'])
            ->get();
        $totals = [];
        foreach ($manualDiscounts as $d) {
            $totals[$d->id] = 0.0;
        }
        if ($manualDiscounts->isEmpty()) {
            return $totals;
        }

        $items = $order->items()->with('product.categories')->get();
        $autoDiscountByItem = $this->mapItemAutoDiscount($items);

        foreach ($items as $item) {
            if ($item->is_gift || ! $item->product_id) {
                continue;
            }
            // Для replace canApplyToProductWithDiscount всегда true, поэтому
            // признак «промо съел manual на этой позиции» = isApplicableToProduct.
            if ($promoCode->isApplicableToProduct($item->product_id)) {
                continue;
            }

            $original      = (float) $item->price + (float) ($item->discount ?? 0);
            // На позициях без промо item.discount = только auto+manual; чтобы
            // получить чистый «manual» вклад, нужно знать auto-долю. Здесь её
            // не знаем, поэтому считаем manual заново как в applyManualDiscountsStacked.
            $autoPerUnit   = 0.0;
            $manualPerUnit = 0.0;
            $autoDiscountId = $autoDiscountByItem[$item->id] ?? null;

            foreach ($manualDiscounts as $discount) {
                if (! $this->discountAppliesToItem($discount, $item)) {
                    continue;
                }
                // Не стекаем дубль «auto + та же скидка как manual» — см.
                // applyManualDiscountsStacked.
                if ($autoDiscountId !== null && (int) $discount->id === (int) $autoDiscountId) {
                    continue;
                }
                $delta = $this->calculateDelta($discount, $original, $autoPerUnit + $manualPerUnit);
                if ($delta <= 0) {
                    continue;
                }
                $manualPerUnit += $delta;
                $totals[$discount->id] += $delta * (int) $item->quantity;
            }
        }

        return $totals;
    }

    /**
     * Построить карту item_id => auto_discount_id для позиций заказа.
     *
     * Auto-скидку формирует {@see OrderValidationService::applyDiscountToProduct}:
     * она мутирует $model->discount_id. Используем её, чтобы понять, какая
     * именно Discount применилась к каждому товару/варианту автоматически
     * (через привязку Product↔Discount). Нужно для дедупликации в случаях,
     * когда та же Discount привязана к заказу как ручная — иначе одна
     * скидка считалась бы дважды.
     *
     * @param  iterable<OrderItem> $items
     * @return array<int,int|null> item_id => discount_id|null
     */
    protected function mapItemAutoDiscount(iterable $items): array
    {
        $map = [];
        foreach ($items as $item) {
            if ($item->is_gift || ! $item->product_id) {
                $map[$item->id] = null;
                continue;
            }
            $model = $item->product_variant_id
                ? ProductVariant::find($item->product_variant_id)
                : Product::find($item->product_id);
            if (! $model) {
                $map[$item->id] = null;
                continue;
            }
            $this->orderValidationService->applyDiscountToProduct($model);
            $map[$item->id] = ((float) ($model->total_discount ?? 0)) > 0.001
                ? ($model->discount_id ?? null)
                : null;
        }
        return $map;
    }

    /**
     * Применить все ручные скидки из pivot к позициям заказа стопкой.
     * % считаются от оригинальной цены, fixed — от остатка.
     * Возвращает суммарную ручную скидку по заказу (для агрегатов).
     *
     * Предусловия: позиции уже содержат auto-скидку (item.price/discount после auto).
     */
    protected function applyManualDiscountsStacked(Order $order): float
    {
        $manualDiscounts = $order->appliedDiscounts()
            ->with(['products:id', 'productVariants:id', 'categories:id'])
            ->get();

        if ($manualDiscounts->isEmpty()) {
            return 0.0;
        }

        $items = $order->items()->with('product.categories')->get();
        $perDiscountTotals = []; // discount_id => сумма экономии по заказу
        $orderManualTotal = 0.0;

        foreach ($manualDiscounts as $discount) {
            $perDiscountTotals[$discount->id] = 0.0;
        }

        // Какая auto-скидка применена к каждой позиции (item_id => discount_id|null).
        // Нужно чтобы не стекать ту же скидку повторно как ручную —
        // защита от дубля «admin прицепил ту же скидку, что уже авто».
        $autoDiscountByItem = $this->mapItemAutoDiscount($items);

        foreach ($items as $item) {
            if ($item->is_gift || ! $item->product_id) {
                continue;
            }

            // На этом этапе price/discount — после авто. Оригинал = price + discount.
            $original = (float) $item->price + (float) ($item->discount ?? 0);
            if ($original <= 0) {
                continue;
            }

            $autoPerUnit = (float) ($item->discount ?? 0);
            $manualPerUnit = 0.0;
            $autoDiscountId = $autoDiscountByItem[$item->id] ?? null;

            foreach ($manualDiscounts as $discount) {
                if (! $this->discountAppliesToItem($discount, $item)) {
                    continue;
                }

                // Если эта же скидка уже применена как auto на этой позиции —
                // не стекаем повторно, чтобы не получить двойную экономию
                // (одна и та же скидка #N через привязку Product↔Discount
                // и одновременно в pivot order_applied_discounts).
                if ($autoDiscountId !== null && (int) $discount->id === (int) $autoDiscountId) {
                    continue;
                }

                $delta = $this->calculateDelta($discount, $original, $autoPerUnit + $manualPerUnit);
                if ($delta <= 0) {
                    continue;
                }

                $manualPerUnit += $delta;
                $perDiscountTotals[$discount->id] += $delta * (int) $item->quantity;
            }

            if ($manualPerUnit <= 0) {
                continue;
            }

            $newPrice = max(0.0, round($original - $autoPerUnit - $manualPerUnit, 2));
            $newDiscount = round($autoPerUnit + $manualPerUnit, 2);

            $item->update([
                'price'    => $newPrice,
                'discount' => $newDiscount,
            ]);

            $orderManualTotal += $manualPerUnit * (int) $item->quantity;
        }

        // Записываем pivot.applied_amount.
        foreach ($perDiscountTotals as $discountId => $total) {
            $order->appliedDiscounts()->updateExistingPivot($discountId, [
                'applied_amount' => round($total, 2),
            ]);
        }

        return round($orderManualTotal, 2);
    }

    /**
     * Считаем дельту скидки за штуку.
     *  - percentage: всегда от оригинальной цены (аддитивно поверх других %)
     *  - fixed:      фиксированная сумма, но не больше остатка цены после уже
     *                накопленных скидок (auto + предыдущие ручные)
     */
    protected function calculateDelta(Discount $discount, float $originalPrice, float $alreadyDiscountedPerUnit): float
    {
        if ($discount->type === 'percentage') {
            $delta = round($originalPrice * (float) $discount->value / 100, 2);
        } elseif ($discount->type === 'fixed') {
            $remaining = max(0.0, $originalPrice - $alreadyDiscountedPerUnit);
            $delta = min((float) $discount->value, $remaining);
        } else {
            return 0.0;
        }

        // Защита: суммарная скидка не должна превышать оригинал.
        $remainingAfterPrev = max(0.0, $originalPrice - $alreadyDiscountedPerUnit);
        return round(min($delta, $remainingAfterPrev), 2);
    }

    /**
     * Применима ли скидка к позиции по её discount_type (all / specific / category).
     */
    protected function discountAppliesToItem(Discount $discount, OrderItem $item): bool
    {
        if ($discount->discount_type === 'all') {
            return true;
        }

        if ($discount->discount_type === 'specific') {
            $productIds = $discount->products->pluck('id')->flip()->all();
            $variantIds = $discount->productVariants->pluck('id')->flip()->all();

            if (isset($productIds[$item->product_id])) {
                return true;
            }
            if ($item->product_variant_id && isset($variantIds[$item->product_variant_id])) {
                return true;
            }
            return false;
        }

        if ($discount->discount_type === 'category') {
            $itemCats = $item->product
                ? $item->product->categories->pluck('id')->toArray()
                : [];
            $discCats = $discount->categories->pluck('id')->toArray();
            return (bool) array_intersect($itemCats, $discCats);
        }

        return false;
    }
}
