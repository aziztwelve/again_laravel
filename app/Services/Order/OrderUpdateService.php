<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Models\PromoCode;
use App\Services\PromoCode\PromoCodeValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderUpdateService
{
    public function __construct(
        protected OrderHistoryService $historyService,
        protected PromoCodeValidationService $promoValidationService,
        protected OrderValidationService $orderValidationService,
        protected OrderCustomFieldsService $customFieldsService,
    ) {}

    /**
     * Обновить данные заказа
     */
    public function update(Order $order, array $data): bool
    {
        try {
            // Снимок отслеживаемых полей для diff'а истории
            $originalSnapshot = [
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_amount' => $order->total_amount,
                'delivery_method_id' => $order->delivery_method_id,
                'delivery_cost' => $order->delivery_cost,
                'notes' => $order->notes,
                'assigned_user_id' => $order->assigned_user_id,
            ];
            // Поля напрямую обновляемые в таблице orders
            $allowedFields = [
                'notes',
                'client_id',
                'status',
                'payment_status',
                'payment_method',
                'source',
                'delivery_method_id',
                'delivery_date',
                'delivery_comment',
            ];

            $filteredData = array_intersect_key(
                array_filter($data, fn ($value) => $value !== null && $value !== ''),
                array_flip($allowedFields)
            );

            // Прикреплённый менеджер обрабатывается отдельно: разрешаем явный null
            // (открепить менеджера от заказа), чего array_filter выше не позволяет.
            if (array_key_exists('assigned_user_id', $data)) {
                $assignedId = $data['assigned_user_id'];
                $filteredData['assigned_user_id'] = ($assignedId === '' || $assignedId === null)
                    ? null
                    : (int) $assignedId;
            }

            // Дата оплаты редактируется вручную в админке. Разрешаем явный null
            // (сбросить дату), чего array_filter выше не позволяет.
            if (array_key_exists('paid_at', $data)) {
                $paidAt = $data['paid_at'];
                $filteredData['paid_at'] = ($paidAt === '' || $paidAt === null)
                    ? null
                    : $this->formatDeliveryDate($paidAt);
            }

            // Автоматическая синхронизация paid_at со сменой статуса оплаты:
            //  - переключили на «Оплачено» и paid_at в запросе не пришла,
            //    а у заказа её ещё нет → ставим текущее время.
            //  - переключили обратно (не «paid») и paid_at в запросе не пришла →
            //    очищаем дату оплаты.
            // Если фронт явно прислал paid_at в этом же запросе — уважаем его значение.
            if (
                array_key_exists('payment_status', $filteredData)
                && ! array_key_exists('paid_at', $data)
            ) {
                $newStatus = $filteredData['payment_status'];
                if ($newStatus instanceof \App\Enums\PaymentStatus) {
                    $newStatus = $newStatus->value;
                }
                $oldStatus = $originalSnapshot['payment_status'];
                if ($oldStatus instanceof \App\Enums\PaymentStatus) {
                    $oldStatus = $oldStatus->value;
                }

                if ($newStatus !== $oldStatus) {
                    if ($newStatus === 'paid') {
                        if (empty($order->paid_at)) {
                            $filteredData['paid_at'] = now()->format('Y-m-d H:i:s');
                        }
                    } else {
                        $filteredData['paid_at'] = null;
                    }
                }
            }

            // Определяем delivery_method_id по имени если пришёл объект delivery_method
            if (! isset($filteredData['delivery_method_id']) && isset($data['delivery_method']['name'])) {
                $method = DeliveryMethod::where('name', $data['delivery_method']['name'])->first();
                if ($method) {
                    $filteredData['delivery_method_id'] = $method->id;
                }
            }

            // Обновляем контактные данные клиента если переданы
            if (isset($data['user']) && is_array($data['user'])) {
                $this->updateClientContactInfo($order, $data['user']);
            }

            // Обработка адреса доставки и/или получателя.
            // Получатель хранится в той же таблице order_addresses (см. migration),
            // поэтому пишем единым updateOrCreate, чтобы не плодить лишние строки.
            $hasAddress = isset($data['delivery_address']) && is_array($data['delivery_address']);
            $hasRecipient = isset($data['recipient']) && is_array($data['recipient']);

            if ($hasAddress) {
                // Если delivery_date есть на верхнем уровне, но нет в delivery_address — добавляем
                if (isset($data['delivery_date']) && ! isset($data['delivery_address']['delivery_date'])) {
                    $data['delivery_address']['delivery_date'] = $data['delivery_date'];
                }

                // Извлекаем delivery_date из delivery_address если есть
                if (array_key_exists('delivery_date', $data['delivery_address'])) {
                    $filteredData['delivery_date'] = $this->formatDeliveryDate($data['delivery_address']['delivery_date']);
                }
            }

            if ($hasAddress || $hasRecipient) {
                $this->updateDeliveryAddress(
                    $order,
                    $data['delivery_address'] ?? [],
                    $data['recipient'] ?? []
                );
            }

            if (isset($data['items'])) {
                $this->updateOrderItems($order, $data['items']);
            }

            // Кастомные поля заказа («Поля заказа»): часть колонок, часть legacy_meta.
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                $this->customFieldsService->update($order, $data['custom_fields']);
            }

            // Обработка промокода — привязка/снятие.
            // Сравниваем только если ключ promo_code пришёл в data, чтобы не сбросить
            // купон при PATCH-обновлении других полей.
            if (array_key_exists('promo_code', $data)) {
                $this->applyPromoCodeChange($order, $data['promo_code'] ?: null);
            }

            $order->update($filteredData);

            // Пишем в историю diff отслеживаемых полей
            $order->refresh();
            $updatedSnapshot = [
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_amount' => $order->total_amount,
                'delivery_method_id' => $order->delivery_method_id,
                'delivery_cost' => $order->delivery_cost,
                'notes' => $order->notes,
                'assigned_user_id' => $order->assigned_user_id,
            ];
            $this->historyService->logUpdated($order, $originalSnapshot, $updatedSnapshot);

            Log::info('Order updated', [
                'order_id' => $order->id,
                'updated_fields' => array_keys($filteredData),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to update order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Обновить контактную информацию клиента заказа
     */
    private function updateClientContactInfo(Order $order, array $userData): void
    {
        $clientId = $order->client_id;

        if (! $clientId) {
            return;
        }

        $client = Client::find($clientId);

        if (! $client) {
            return;
        }

        $updateData = array_filter([
            'first_name' => $userData['first_name'] ?? null,
            'last_name'  => $userData['last_name'] ?? null,
            'phone'      => $userData['phone'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($updateData)) {
            $client->update($updateData);
        }
    }

    /**
     * Форматировать дату доставки
     */
    private function formatDeliveryDate($date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning('Failed to parse delivery_date', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Обновить адрес доставки заказа
     */
    private function updateDeliveryAddress(Order $order, array $addressData, array $recipientData = []): void
    {
        $payload = [];

        // Обновляем только те поля, которые реально пришли в запросе,
        // чтобы пустой recipient не затирал существующего получателя и наоборот.
        if (! empty($addressData)) {
            $payload += [
                'country' => $addressData['country'] ?? null,
                'region' => $addressData['region'] ?? null,
                'city' => $addressData['city'] ?? null,
                'postal_code' => $addressData['postal_code'] ?? null,
                'address' => $addressData['address'] ?? null,
                'entrance' => $addressData['entrance'] ?? null,
                'floor' => $addressData['floor'] ?? null,
                'intercom' => $addressData['intercom'] ?? null,
                'delivery_comment' => $addressData['delivery_comment'] ?? null,
                'delivery_date' => $this->formatDeliveryDate($addressData['delivery_date'] ?? null),
                'buyer_comment' => $addressData['buyer_comment'] ?? null,
            ];
        }

        if (! empty($recipientData)) {
            $payload += [
                'recipient_first_name' => $recipientData['first_name'] ?? null,
                'recipient_last_name' => $recipientData['last_name'] ?? null,
                'recipient_middle_name' => $recipientData['middle_name'] ?? null,
                'recipient_phone' => $recipientData['phone'] ?? null,
            ];
        }

        if (empty($payload)) {
            return;
        }

        $order->address()->updateOrCreate(
            ['order_id' => $order->id],
            $payload
        );
    }

    /**
     * Обновить товары в заказе.
     *
     * Логика «удалить и пересоздать» опасна для импортированных заказов:
     * у их позиций product_id = null (товар по SKU не разрешился), и любой
     * пустой/некорректный $items ранее затирал валидные данные. Поэтому:
     *
     *   1) Если на вход пришёл пустой массив (после фильтрации) — НЕ трогаем
     *      существующие позиции. PUT с пустыми items ничем не отличается от
     *      PUT без items, кроме как ошибкой клиента.
     *   2) Принимаем legacy-позиции с legacy_sku/legacy_name, чтобы можно
     *      было редактировать импортированные заказы, не теряя данные.
     */
    private function updateOrderItems(Order $order, array $items): void
    {
        // Отбрасываем мусор: позиция должна иметь либо product_id, либо
        // хотя бы legacy_sku/legacy_name (импортированные заказы), и
        // положительное quantity.
        $valid = array_values(array_filter(
            $items,
            function ($item) {
                if (! is_array($item)) {
                    return false;
                }
                $hasProduct = ! empty($item['product_id']);
                $hasLegacy = ! empty($item['legacy_sku']) || ! empty($item['legacy_name']);
                $qty = (int) ($item['quantity'] ?? 0);

                return ($hasProduct || $hasLegacy) && $qty > 0;
            }
        ));

        // Защита: пустой/полностью невалидный массив НЕ должен сносить позиции.
        if (empty($valid)) {
            Log::warning('OrderUpdate: items payload is empty/invalid, skipping items wipe', [
                'order_id' => $order->id,
                'received_count' => count($items),
            ]);

            return;
        }

        $order->items()->delete();

        foreach ($valid as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'] ?? null,
                // В таблице order_items есть только product_variant_id.
                // Принимаем оба ключа от фронта на случай legacy-кода.
                'product_variant_id' => $item['product_variant_id']
                    ?? $item['variant_id']
                    ?? null,
                'color_id' => $item['color_id'] ?? null,
                'legacy_sku' => $item['legacy_sku'] ?? null,
                'legacy_name' => $item['legacy_name'] ?? null,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }

        $itemsTotal = $order->items()->where('is_gift', false)->sum(DB::raw('quantity * price'));
        $delivery   = (float) ($order->delivery_cost ?? 0);
        $giftCard   = (float) ($order->gift_card_amount ?? 0);
        $total = max(0, $itemsTotal + $delivery - $giftCard);
        $order->update(['total_amount' => $total]);
    }

    /**
     * Переприменить промокод к заказу заново (например, после снятия ручной скидки).
     * Чисто снимает текущий промокод (удаляет usage, декрементит times_used)
     * и применяет тот же код заново к актуальным ценам позиций.
     */
    public function reapplyPromoCode(Order $order): void
    {
        $promoCode = $order->promo_code_id
            ? PromoCode::find($order->promo_code_id)
            : null;

        if (!$promoCode) {
            return;
        }

        $code = $promoCode->code;

        // Самостоятельно снимаем старое использование, чтобы applyPromoCodeChange
        // не создал дубль usage и не инкрементировал times_used повторно.
        $promoCode->usages()->where('order_id', $order->id)->delete();
        $promoCode->decrement('times_used');

        // Сбрасываем привязку и в памяти, и в БД, чтобы applyPromoCodeChange
        // не пошёл по ветке «без изменений» и не пытался ещё раз снять промо.
        $order->promo_code_id = null;
        $order->save();

        $this->applyPromoCodeChange($order, $code);
    }

    /**
     * Применить/снять промокод к существующему заказу.
     * Вызывается из update() когда в payload пришёл ключ `promo_code`.
     *
     * Логика:
     * - Если код пустой и был привязан — снимаем (decrement times_used + удаляем usage).
     * - Если код задан и совпадает с текущим — ничего не делаем.
     * - Иначе валидируем новый код и привязываем (заменяя предыдущий, если был).
     */
    private function applyPromoCodeChange(Order $order, ?string $newCode): void
    {
        $currentPromoId = $order->promo_code_id;
        $currentCode = $currentPromoId
            ? optional(PromoCode::find($currentPromoId))->code
            : null;

        $newCode = $newCode !== null ? trim($newCode) : null;
        if ($newCode === '') {
            $newCode = null;
        }

        // Без изменений
        if ($newCode === $currentCode) {
            return;
        }

        // Снятие старого промокода (если был): удаляем usage, decrement times_used.
        // Цены и pivot ручных скидок ниже пересчитает OrderDiscountService::recalculate().
        if ($currentPromoId) {
            $oldPromo = PromoCode::find($currentPromoId);
            if ($oldPromo) {
                $oldPromo->usages()
                    ->where('order_id', $order->id)
                    ->delete();
                $oldPromo->decrement('times_used');
            }
            $order->promo_code_id = null;
        }

        // Если новый код пустой — просто очистка промокода и пересчёт.
        if ($newCode === null) {
            $order->save();
            $this->resolveDiscountService()->recalculate($order->fresh());
            return;
        }

        // Валидация нового промокода
        $client = $order->client;
        if (! $client) {
            Log::warning('OrderUpdate: client missing, cannot validate promo code', [
                'order_id' => $order->id,
            ]);
            // Сохраним состояние с уже отвязанным старым промо.
            $order->save();
            $this->resolveDiscountService()->recalculate($order->fresh());
            return;
        }

        $validation = $this->promoValidationService->validate($newCode, $client);
        if (! $validation['success']) {
            // Не падаем — просто не применяем новый. Старый уже отвязан.
            // Контроллер для валидации нового кода должен использовать /api/promo-codes/validate.
            Log::info('OrderUpdate: promo code validation failed', [
                'order_id' => $order->id,
                'code' => $newCode,
                'reason' => $validation['code'] ?? 'unknown',
            ]);
            $order->save();
            $this->resolveDiscountService()->recalculate($order->fresh());
            return;
        }

        $promoCode = $validation['promo_code'];

        // Привязываем промокод и фиксируем usage / times_used.
        // Сами цены позиций, total_items_discount, total_promo_discount, pivot
        // ручных скидок пересчитает OrderDiscountService::recalculate() ниже —
        // он уважает discount_behavior (replace/stack/skip) и стекает ручные
        // скидки поверх auto, что эта функция исторически делала неполно.
        $order->promo_code_id = $promoCode->id;
        $order->save();

        $promoCode->usages()->create([
            'client_id'       => $client->id,
            'order_id'        => $order->id,
            'discount_amount' => 0, // обновим после recalc, когда узнаем точную сумму
        ]);
        $promoCode->increment('times_used');

        $fresh = $order->fresh();
        $this->resolveDiscountService()->recalculate($fresh);
        $fresh->refresh();

        // Сохраняем total_amount_original = сумма оригиналов (до скидок).
        $totalDiscountAmount = (float) ($fresh->total_items_discount ?? 0)
            + (float) ($fresh->total_promo_discount ?? 0);
        $totalOriginal = $fresh->items()
            ->where('is_gift', false)
            ->sum(\Illuminate\Support\Facades\DB::raw('quantity * price'))
            + $totalDiscountAmount;
        $fresh->total_amount_original = round((float) $totalOriginal, 2);
        $fresh->save();

        // Обновляем discount_amount в записи usage актуальной суммой.
        $promoCode->usages()
            ->where('order_id', $order->id)
            ->update(['discount_amount' => round((float) $fresh->total_promo_discount, 2)]);
    }

    /**
     * Лениво резолвим OrderDiscountService, чтобы не плодить циклическую
     * зависимость в конструкторе (OrderDiscountService -> OrderUpdateService).
     */
    private function resolveDiscountService(): \App\Services\Order\OrderDiscountService
    {
        return app(\App\Services\Order\OrderDiscountService::class);
    }

    /**
     * Переприменить «авто-скидки» (от привязки Product↔Discount) к позициям заказа.
     *
     * Используется при снятии ручной скидки или промокода: после того как мы
     * восстановили item.price до состояния без скидок, нам нужно вернуть на
     * место авто-скидку, иначе пропадает зачёркнутая цена и блок «Скидка»
     * исчезает целиком (даже если у товара действительно есть скидка
     * через Discount-привязку).
     *
     * Логика: для каждой не-подарочной позиции грузим product / variant,
     * прогоняем через applyDiscountToProduct (тот же путь, что в каталоге
     * и валидации заказа), записываем item.price = price_after_discount,
     * item.discount = total_discount per unit.
     *
     * После выполнения order.total_items_discount пересчитан по items.
     * Поле total_promo_discount этот метод не трогает.
     */
    public function reapplyAutoDiscounts(Order $order): void
    {
        $totalItemsDiscount = 0.0;

        foreach ($order->items()->get() as $item) {
            if ($item->is_gift || ! $item->product_id) {
                continue;
            }

            $model = $item->product_variant_id
                ? \App\Models\ProductVariant::find($item->product_variant_id)
                : \App\Models\Product::find($item->product_id);

            if (! $model) {
                continue;
            }

            // applyDiscountToProduct мутирует модель: model->price становится
            // ценой после авто-скидки, model->total_discount — размер скидки.
            $this->orderValidationService->applyDiscountToProduct($model);

            $perUnitDiscount = (float) ($model->total_discount ?? 0);
            $newPrice = (float) $model->price;

            $item->update([
                'price'    => round($newPrice, 2),
                'discount' => round($perUnitDiscount, 2),
            ]);

            $totalItemsDiscount += $perUnitDiscount * (int) $item->quantity;
        }

        $order->total_items_discount = round($totalItemsDiscount, 2);
        $order->discount_amount = round(
            (float) $order->total_items_discount + (float) ($order->total_promo_discount ?? 0),
            2
        );
        $order->save();
    }

    /**
     * Проверить можно ли редактировать заказ
     */
    public function canUpdate(Order $order): bool
    {
        // Можно редактировать заказы в любом статусе, кроме отмененных и доставленных
        return ! in_array($order->status, [
            OrderStatus::CANCELLED,
            OrderStatus::DELIVERED,
        ]);
    }
}
