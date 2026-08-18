<?php

namespace App\Services\Delivery;

use App\Models\City;
use App\Models\Country;
use App\Models\FreeShippingRule;
use App\Models\Order;
use App\Models\Region;
use App\Services\Delivery\FreeShipping\FreeShippingContext;
use App\Services\Delivery\FreeShipping\FreeShippingMatch;
use Illuminate\Support\Collection;

/**
 * Бесплатная доставка по гибким правилам (см. docs/tasks/free-shipping.md).
 *
 * Правило = набор условий (служба, вид доставки, товары, страны, регионы,
 * способы оплаты) + порог суммы выкупа. Пустое условие означает «любое».
 * Сумма выкупа считается ПОСЛЕ товарных скидок, промокода и акций, поэтому
 * применение к заказу выполняется уже после их применения.
 */
class FreeShippingService
{
    /** Кеш активных правил в пределах одного запроса. */
    private ?Collection $rulesCache = null;

    /**
     * Лучшее сработавшее правило для контекста или NULL.
     *
     * Из нескольких подходящих выигрывает правило с наименьшим порогом
     * (решение #6 спеки — выгода на стороне покупателя).
     */
    public function evaluate(FreeShippingContext $context): ?FreeShippingMatch
    {
        $context = $this->withResolvedGeo($context);

        foreach ($this->activeRules() as $rule) {
            if (! $this->conditionsMatch($rule, $context)) {
                continue;
            }

            $amount = $this->qualifyingAmount($rule, $context);

            // Порог включительный. Допуск в 0.01 — против артефактов float.
            if ($amount + 0.001 >= (float) $rule->min_order_amount) {
                return new FreeShippingMatch(
                    (int) $rule->id,
                    (string) $rule->name,
                    (float) $rule->min_order_amount,
                    round($amount, 2),
                );
            }
        }

        return null;
    }

    /**
     * Ближайшее правило, до которого не хватает суммы — для подсказки
     * «до бесплатной доставки осталось N ₽».
     *
     * Матчинг здесь щадящий (lenient): неизвестные в контексте параметры
     * (служба/вид доставки/оплата/гео ещё не выбраны в корзине) условие не
     * рубят — покупатель ещё может выбрать подходящий вариант.
     *
     * @return array{rule_id:int, rule_name:string, min_order_amount:float, qualifying_amount:float, remaining:float}|null
     */
    public function progress(FreeShippingContext $context): ?array
    {
        $context = $this->withResolvedGeo($context);
        $best = null;

        foreach ($this->activeRules() as $rule) {
            if (! $this->conditionsMatch($rule, $context, lenient: true)) {
                continue;
            }

            $amount = $this->qualifyingAmount($rule, $context);
            $remaining = round((float) $rule->min_order_amount - $amount, 2);

            if ($remaining <= 0) {
                // Этот порог уже взят — смотрим следующий (более высокий) тариф.
                continue;
            }

            if ($best === null || $remaining < $best['remaining']) {
                $best = [
                    'rule_id' => (int) $rule->id,
                    'rule_name' => (string) $rule->name,
                    'min_order_amount' => round((float) $rule->min_order_amount, 2),
                    'qualifying_amount' => round($amount, 2),
                    'remaining' => $remaining,
                ];
            }
        }

        return $best;
    }

    /**
     * Оценка списка вариантов доставки, показанных покупателю.
     *
     * @param  array<int, array{key?:string, service?:string, delivery_type?:string, price?:float}>  $candidates
     * @return array<int, array{key:string|null, service:string|null, delivery_type:string|null, is_free:bool, price:float, original_price:float, rule:array|null}>
     */
    public function evaluateCandidates(FreeShippingContext $context, array $candidates): array
    {
        $context = $this->withResolvedGeo($context);
        $result = [];

        foreach ($candidates as $candidate) {
            $service = $this->normalizeService($candidate['service'] ?? null);
            $type = $this->normalizeDeliveryType($candidate['delivery_type'] ?? null);
            $price = round((float) ($candidate['price'] ?? 0), 2);

            $match = $this->evaluate($context->withDelivery($service, $type));

            $result[] = [
                'key' => isset($candidate['key']) ? (string) $candidate['key'] : null,
                'service' => $service,
                'delivery_type' => $type,
                'is_free' => $match !== null,
                'price' => $match !== null ? 0.0 : $price,
                'original_price' => $price,
                'rule' => $match?->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Применить правила к заказу: обнулить доставку при матче либо вернуть
     * тарифную цену, если правило больше не подходит.
     *
     * Идемпотентно: повторный вызов не «схлопывает» исходную цену тарифа,
     * она сохраняется в orders.delivery_cost_original.
     *
     * @param  array{country_id?:int|null, region_id?:int|null, city_id?:int|null}  $geoOverrides
     *         Идентификаторы гео из payload чекаута (точнее, чем матч по названиям).
     */
    public function applyToOrder(Order $order, array $geoOverrides = []): ?FreeShippingMatch
    {
        $order->loadMissing(['items', 'address', 'deliveryMethod']);

        $context = $this->contextFromOrder($order, $geoOverrides);
        $match = $this->evaluate($context);

        // Базовая (тарифная) цена доставки: если правило уже применялось,
        // она лежит в delivery_cost_original.
        $base = $order->delivery_cost_original !== null
            ? (float) $order->delivery_cost_original
            : (float) ($order->delivery_cost ?? 0);

        if ($match !== null) {
            $order->forceFill([
                'delivery_cost_original' => $base,
                'delivery_cost' => 0,
                'free_shipping_rule_id' => $match->ruleId,
            ])->save();
        } elseif ($order->free_shipping_rule_id !== null || $order->delivery_cost_original !== null) {
            // Условия перестали выполняться (например, акция уронила сумму
            // выкупа ниже порога) — возвращаем платную доставку.
            $order->forceFill([
                'delivery_cost' => $base,
                'delivery_cost_original' => null,
                'free_shipping_rule_id' => null,
            ])->save();
        }

        $order->updateTotalAmount();

        return $match;
    }

    /**
     * Контекст из заказа. Позиции берём из order_items (там финальные цены
     * после всех скидок), гео — из ids payload'а с фолбэком на названия.
     */
    public function contextFromOrder(Order $order, array $geoOverrides = []): FreeShippingContext
    {
        $items = $order->items
            ->filter(fn ($item) => ! (bool) $item->is_gift)
            ->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->price,
                'is_gift' => false,
            ])
            ->values()
            ->all();

        $deliveryData = is_array($order->delivery_data) ? $order->delivery_data : [];
        $methodCode = $order->deliveryMethod?->code;

        return new FreeShippingContext(
            items: $items,
            service: $this->resolveService($deliveryData, $methodCode),
            deliveryType: $this->resolveDeliveryType($deliveryData, $methodCode),
            paymentMethod: $order->payment_method ? (string) $order->payment_method : null,
            countryId: $this->intOrNull($geoOverrides['country_id'] ?? null),
            regionId: $this->intOrNull($geoOverrides['region_id'] ?? null),
            cityId: $this->intOrNull($geoOverrides['city_id'] ?? null),
            countryName: $order->address?->country ?? $order->country_code,
            regionName: $order->address?->region,
            cityName: $order->address?->city ?? $order->city_name,
        );
    }

    /**
     * Служба доставки: приоритет — delivery_data.provider, фолбэк — код
     * способа доставки (cdek_* / yandex_*).
     */
    public function resolveService(array $deliveryData, ?string $methodCode): ?string
    {
        $service = $this->normalizeService($deliveryData['provider'] ?? null);

        if ($service !== null) {
            return $service;
        }

        $map = config('free_shipping.method_code_map', []);

        return $methodCode && isset($map[$methodCode]) ? $map[$methodCode]['service'] : null;
    }

    /**
     * Вид доставки: приоритет — delivery_data.delivery_type, фолбэк — код
     * способа доставки. Постамат считается ПВЗ.
     */
    public function resolveDeliveryType(array $deliveryData, ?string $methodCode): ?string
    {
        $type = $this->normalizeDeliveryType($deliveryData['delivery_type'] ?? null);

        if ($type !== null) {
            return $type;
        }

        $map = config('free_shipping.method_code_map', []);

        return $methodCode && isset($map[$methodCode]) ? $map[$methodCode]['delivery_type'] : null;
    }

    /**
     * Сумма выкупа для правила: сумма (кол-во × финальная цена) по не-подарочным
     * позициям. Если в правиле выбраны товары — считаем только их (решение #2).
     */
    private function qualifyingAmount(FreeShippingRule $rule, FreeShippingContext $context): float
    {
        $productIds = $rule->products->pluck('id')->map(fn ($id) => (int) $id)->all();
        $total = 0.0;

        foreach ($context->items as $item) {
            if (! empty($item['is_gift'])) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);

            if ($productIds !== [] && ! in_array($productId, $productIds, true)) {
                continue;
            }

            $total += (int) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Проверка всех условий правила, кроме порога суммы.
     *
     * @param  bool  $lenient  NULL в контексте не рубит условие (режим подсказки).
     */
    private function conditionsMatch(FreeShippingRule $rule, FreeShippingContext $context, bool $lenient = false): bool
    {
        if (! $this->inList($rule->services, $context->service, $lenient)) {
            return false;
        }

        if (! $this->inList($rule->delivery_types, $context->deliveryType, $lenient)) {
            return false;
        }

        if (! $this->inList($rule->payment_methods, $context->paymentMethod, $lenient)) {
            return false;
        }

        $countryIds = $rule->countries->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $this->idInList($countryIds, $context->countryId, $lenient)) {
            return false;
        }

        $regionIds = $rule->regions->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $this->idInList($regionIds, $context->regionId, $lenient)) {
            return false;
        }

        // Товарное условие: в корзине должен быть хотя бы один из выбранных.
        $productIds = $rule->products->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($productIds !== []) {
            $hasAny = false;
            foreach ($context->items as $item) {
                if (! empty($item['is_gift'])) {
                    continue;
                }
                if (in_array((int) ($item['product_id'] ?? 0), $productIds, true)) {
                    $hasAny = true;
                    break;
                }
            }

            if (! $hasAny) {
                return false;
            }
        }

        return true;
    }

    /**
     * Пустой список условия = «любое значение».
     * NULL в контексте: строгий режим — не совпало, щадящий — совпало.
     */
    private function inList(?array $allowed, ?string $value, bool $lenient): bool
    {
        $allowed = array_values(array_filter((array) $allowed, fn ($v) => $v !== null && $v !== ''));

        if ($allowed === []) {
            return true;
        }

        if ($value === null || $value === '') {
            return $lenient;
        }

        return in_array($value, $allowed, true);
    }

    /**
     * То же для числовых справочников. ВНИМАНИЕ: id = 0 — валидное значение
     * (Россия в таблице `country`), поэтому сравнение только с NULL.
     */
    private function idInList(array $allowed, ?int $value, bool $lenient): bool
    {
        if ($allowed === []) {
            return true;
        }

        if ($value === null) {
            return $lenient;
        }

        return in_array($value, $allowed, true);
    }

    /**
     * Достраивает гео: город → регион → страна. Если ids не переданы —
     * пробуем сопоставить по названиям (регистронезависимо).
     */
    private function withResolvedGeo(FreeShippingContext $context): FreeShippingContext
    {
        if ($context->countryId !== null && $context->regionId !== null) {
            return $context;
        }

        $resolved = clone $context;

        if ($resolved->cityId === null && $resolved->cityName) {
            $resolved->cityId = $this->intOrNull(
                City::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($resolved->cityName))])->value('id')
            );
        }

        if ($resolved->regionId === null && $resolved->cityId !== null) {
            $resolved->regionId = $this->intOrNull(
                City::whereKey($resolved->cityId)->value('region_id')
            );
        }

        if ($resolved->regionId === null && $resolved->regionName) {
            $resolved->regionId = $this->intOrNull(
                Region::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($resolved->regionName))])->value('id')
            );
        }

        if ($resolved->countryId === null && $resolved->regionId !== null) {
            $resolved->countryId = $this->intOrNull(
                Region::whereKey($resolved->regionId)->value('country_id')
            );
        }

        if ($resolved->countryId === null && $resolved->countryName) {
            $needle = mb_strtolower(trim($resolved->countryName));
            $resolved->countryId = $this->intOrNull(
                Country::whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(code) = ?', [$needle])
                    ->value('id')
            );
        }

        return $resolved;
    }

    private function activeRules(): Collection
    {
        if ($this->rulesCache !== null) {
            return $this->rulesCache;
        }

        return $this->rulesCache = FreeShippingRule::query()
            ->active()
            ->with(['products:id', 'countries:id', 'regions:id'])
            // Меньший порог — раньше: из нескольких подходящих правил
            // выигрывает самое выгодное для покупателя.
            ->orderBy('min_order_amount')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /** Сбросить кеш правил (нужен в тестах и после изменения правил). */
    public function flushCache(): void
    {
        $this->rulesCache = null;
    }

    private function normalizeService(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return array_key_exists($value, config('free_shipping.services', [])) ? $value : null;
    }

    private function normalizeDeliveryType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        // Постамат/ПВЗ — один и тот же вид доставки.
        if (in_array($value, ['postamat', 'pvz', 'pickup_point'], true)) {
            return 'pickup';
        }

        return array_key_exists($value, config('free_shipping.delivery_types', [])) ? $value : null;
    }

    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
