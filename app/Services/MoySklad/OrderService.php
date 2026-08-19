<?php

namespace App\Services\MoySklad;

use App\Models\Order;
use App\Models\OrderItem;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис выгрузки заказов покупателей в МойСклад.
 *
 * Создаёт документ «Заказ покупателя» (customerorder) через JSON API 1.2.
 * Вызывать метод pushOrder() вручную или из события/хука после оформления заказа.
 *
 * ИСПОЛЬЗОВАНИЕ:
 *   $service = new \App\Services\MoySklad\OrderService();
 *   $msOrderId = $service->pushOrder($order);
 */
class OrderService
{
    private string $baseURL = 'https://api.moysklad.ru/api/remap/1.2';
    private string $token;

    public function __construct()
    {
        $settings = \App\Models\DeliveryServiceSetting
            ::where('service_name', 'moysklad')
            ->first();

        if (! $settings) {
            throw new Exception('Настройки для МойСклад не найдены. Пожалуйста, настройте сервис в админке.');
        }

        $this->token = $settings->token;
    }

    // -------------------------------------------------------------------------
    // Публичный API
    // -------------------------------------------------------------------------

    /**
     * Выгрузить заказ в МойСклад: создаёт документ «Заказ покупателя» при
     * первой синхронизации либо обновляет существующий (если у заказа уже
     * сохранён moysklad_order_uuid). Сохраняет результат в
     * order.moysklad_order_uuid / order.moysklad_synced_at.
     *
     * @param  Order  $order  Заказ с подгруженными items и address
     * @return string UUID заказа в МойСклад
     *
     * @throws Exception
     */
    public function pushOrder(Order $order): string
    {
        $order->loadMissing(['items.variant', 'items.product', 'address', 'client', 'deliveryMethod']);

        $organizationMeta = $this->getOrganizationMeta();
        $agentMeta        = $this->resolveAgentMeta($order);
        $positions        = $this->buildPositions($order);

        $this->logIncompletePositionsIfAny($order, $positions);

        $payload = [
            'organization' => ['meta' => $organizationMeta],
            'agent'        => ['meta' => $agentMeta],
            'name'         => (string) $order->order_number,
            'externalCode' => (string) $order->id,
            'moment'       => $order->created_at->format('Y-m-d H:i:s'),
            'description'  => $this->buildDescription($order),
            'positions'    => $positions,
        ];

        // Адрес доставки (строкой — самый простой вариант)
        $shipmentAddress = $this->buildShipmentAddress($order);
        if ($shipmentAddress) {
            $payload['shipmentAddress'] = $shipmentAddress;
        }

        // Статус документа в МойСклад — сопоставляем по названию статуса
        // заказа на сайте (OrderStatus::label()) с настроенными статусами
        // документа «Заказ покупателя» в конкретном аккаунте МойСклад.
        // Если совпадения нет (аккаунт не настроен под наши статусы) —
        // просто не передаём state, МойСклад оставит текущий/дефолтный.
        $stateMeta = $this->resolveStateMeta($order->status);
        if ($stateMeta) {
            $payload['state'] = ['meta' => $stateMeta];
        }

        // Планируемая дата доставки
        if ($order->delivery_date) {
            $payload['deliveryPlannedMoment'] = $order->delivery_date->format('Y-m-d H:i:s');
        }

        $isUpdate = ! empty($order->moysklad_order_uuid);

        $request = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type'    => 'application/json',
        ]);

        $response = $isUpdate
            ? $request->put("{$this->baseURL}/entity/customerorder/{$order->moysklad_order_uuid}", $payload)
            : $request->post("{$this->baseURL}/entity/customerorder", $payload);

        if (! $response->successful()) {
            Log::error('MoySklad: ошибка ' . ($isUpdate ? 'обновления' : 'создания') . ' заказа', [
                'order_id' => $order->id,
                'status'   => $response->status(),
                'body'     => $response->body(),
                'payload'  => $payload,
            ]);

            throw new Exception(
                'МойСклад: не удалось ' . ($isUpdate ? 'обновить' : 'создать') . ' заказ. '
                . ($response->json('errors.0.error') ?? $response->body())
            );
        }

        $msOrderId = $response->json('id');

        $order->forceFill([
            'moysklad_order_uuid' => $msOrderId,
            'moysklad_synced_at' => now(),
        ])->save();

        Log::info('MoySklad: заказ успешно ' . ($isUpdate ? 'обновлён' : 'создан'), [
            'order_id'    => $order->id,
            'ms_order_id' => $msOrderId,
        ]);

        return $msOrderId;
    }

    /**
     * Позиции без uuid варианта (товар не синхронизирован с МойСклад)
     * тихо пропускаются в buildPositions(). Логируем это явно как
     * предупреждение — иначе в МойСклад уйдёт заказ с неполным составом
     * без каких-либо следов проблемы.
     */
    private function logIncompletePositionsIfAny(Order $order, array $positions): void
    {
        $totalItems = $order->items->count();

        if ($totalItems > 0 && count($positions) < $totalItems) {
            $skippedItems = $order->items->filter(fn ($item) => ! ($item->variant?->uuid));

            Log::warning('MoySklad: заказ выгружается с неполным составом — часть позиций не синхронизирована', [
                'order_id'      => $order->id,
                'total_items'   => $totalItems,
                'synced_items'  => count($positions),
                'skipped_items' => $skippedItems->map(fn ($item) => [
                    'order_item_id' => $item->id,
                    'product_id'    => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name'  => $item->product?->name,
                ])->values()->all(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Получить meta первого юрлица из МойСклад.
     *
     * @return array
     *
     * @throws Exception
     */
    public function getOrganizationMeta(): array
    {
        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get("{$this->baseURL}/entity/organization", ['limit' => 1]);

        if (! $response->successful() || empty($response->json('rows'))) {
            throw new Exception('МойСклад: не удалось получить юрлицо (organization).');
        }

        return $response->json('rows.0.meta');
    }

    /**
     * Найти meta статуса документа «Заказ покупателя» в МойСклад, название
     * которого совпадает с label() текущего статуса заказа на сайте.
     * Список статусов кэшируется на 10 минут — он специфичен для аккаунта
     * и почти никогда не меняется, а дёргать API на каждый pushOrder()
     * избыточно.
     *
     * @return array|null meta статуса, либо null если совпадения не нашлось
     *                     (аккаунт МойСклад не настроен под наши статусы)
     */
    private function resolveStateMeta(\App\Enums\OrderStatus $status): ?array
    {
        $states = \Illuminate\Support\Facades\Cache::remember(
            'moysklad:customerorder:states',
            600,
            function () {
                $response = Http::withHeaders([
                    'Authorization'   => 'Bearer ' . $this->token,
                    'Accept-Encoding' => 'gzip',
                ])->get("{$this->baseURL}/entity/customerorder/metadata");

                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json('states') ?? [])
                    ->mapWithKeys(fn ($state) => [$state['name'] => $state['meta']])
                    ->all();
            }
        );

        return $states[$status->label()] ?? null;
    }

    /**
     * Найти или создать контрагента в МойСклад и вернуть его meta.
     *
     * Поиск идёт по email или телефону. Если не найден — создаётся новый.
     *
     * @param  Order  $order
     * @return array
     *
     * @throws Exception
     */
    private function resolveAgentMeta(Order $order): array
    {
        $counterpartyService = new CounterpartyService();

        return $counterpartyService->findOrCreateMeta($order);
    }

    /**
     * Сформировать массив позиций заказа для МойСклад.
     *
     * @param  Order  $order
     * @return array
     */
    private function buildPositions(Order $order): array
    {
        $positions = [];

        foreach ($order->items as $item) {
            $variantUuid = $item->variant?->uuid ?? null;

            // Если нет UUID варианта в МС — пропускаем с предупреждением
            if (! $variantUuid) {
                Log::warning('MoySklad: позиция пропущена — нет uuid варианта', [
                    'order_id'          => $order->id,
                    'order_item_id'     => $item->id,
                    'product_variant_id'=> $item->product_variant_id,
                ]);
                continue;
            }

            $priceKopecks = (int) round((float) $item->price * 100);

            // discount в order_items хранится как рублёвая сумма скидки на позицию.
            // МойСклад принимает скидку только в процентах (0–100).
            // Конвертируем: discountPct = (discountRub / price) * 100.
            // Если цена 0 или скидки нет — передаём 0.
            $discountPct = 0;
            if ($item->discount && $item->price > 0) {
                $discountPct = round((float) $item->discount / (float) $item->price * 100, 2);
                // МС не принимает дробные проценты — округляем до целого
                $discountPct = (int) round($discountPct);
                // Защита от некорректных значений
                $discountPct = max(0, min(100, $discountPct));
            }

            $positions[] = [
                'assortment' => [
                    'meta' => [
                        'href'      => "{$this->baseURL}/entity/variant/{$variantUuid}",
                        'type'      => 'variant',
                        'mediaType' => 'application/json',
                    ],
                ],
                // Цена в копейках
                'price'    => $priceKopecks,
                'quantity' => (float) $item->quantity,
                // Скидка в процентах (0 если нет скидки)
                'discount' => $discountPct,
            ];
        }

        return $positions;
    }

    /**
     * Собрать адрес доставки в строку для поля shipmentAddress.
     *
     * @param  Order  $order
     * @return string|null
     */
    private function buildShipmentAddress(Order $order): ?string
    {
        $addr = $order->address;

        if (! $addr) {
            return null;
        }

        $parts = array_filter([
            $addr->postal_code,
            $addr->country,
            $addr->region,
            $addr->city,
            $addr->address,
            $addr->house ?? null,
            $addr->apartment ? 'кв. ' . $addr->apartment : null,
        ]);

        return implode(', ', $parts) ?: null;
    }

    /**
     * Сформировать комментарий к заказу.
     *
     * @param  Order  $order
     * @return string
     */
    private function buildDescription(Order $order): string
    {
        $parts = [];

        if ($order->notes) {
            $parts[] = 'Примечание: ' . $order->notes;
        }

        if ($order->deliveryMethod?->name) {
            $parts[] = 'Доставка: ' . $order->deliveryMethod->name;
        }

        if ($order->payment_method) {
            $parts[] = 'Оплата: ' . $order->payment_method;
        }

        return implode(' | ', $parts);
    }
}
