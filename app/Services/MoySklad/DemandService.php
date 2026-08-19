<?php

namespace App\Services\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис физического списания/возврата товара по заказу в МойСклад через
 * документ «Отгрузка» (demand).
 *
 * В отличие от документа «Заказ покупателя» (customerorder), который не
 * влияет на физический остаток (см. OrderService::pushOrder()), demand
 * реально списывает товар со склада при applicable=true и возвращает его
 * обратно при applicable=false (подтверждено тестом — распроведение
 * demand откатывает списание без удаления документа).
 */
class DemandService
{
    private string $baseURL = 'https://api.moysklad.ru/api/remap/1.2';
    private string $token;

    public function __construct()
    {
        $settings = DeliveryServiceSetting::where('service_name', 'moysklad')->first();

        if (! $settings) {
            throw new Exception('Настройки для МойСклад не найдены. Пожалуйста, настройте сервис в админке.');
        }

        $this->token = $settings->token;
    }

    /**
     * Списать товар по заказу: создать (если ещё нет) и провести документ
     * «Отгрузка», привязанный к заказу через customerOrder. Идемпотентно —
     * повторный вызов для уже списанного заказа ничего не делает.
     *
     * @return string UUID документа demand в МойСклад
     *
     * @throws Exception
     */
    public function shipOrder(Order $order): string
    {
        if ($order->moysklad_demand_uuid) {
            return $order->moysklad_demand_uuid;
        }

        if (! $order->moysklad_order_uuid) {
            throw new Exception('МойСклад: заказ ещё не выгружен (нет moysklad_order_uuid), нельзя создать отгрузку.');
        }

        $order->loadMissing(['items.variant', 'address', 'client']);

        $orderService = new OrderService();
        $organizationMeta = $orderService->getOrganizationMeta();
        $agentMeta = (new CounterpartyService())->findOrCreateMeta($order);
        $storeMeta = $this->getDefaultStoreMeta();
        $positions = $this->buildPositions($order);

        if (empty($positions)) {
            throw new Exception('МойСклад: нет позиций с известным uuid варианта, отгрузку создать нельзя.');
        }

        $payload = [
            'organization' => ['meta' => $organizationMeta],
            'agent' => ['meta' => $agentMeta],
            'store' => ['meta' => $storeMeta],
            'customerOrder' => [
                'meta' => [
                    'href' => "{$this->baseURL}/entity/customerorder/{$order->moysklad_order_uuid}",
                    'type' => 'customerorder',
                    'mediaType' => 'application/json',
                ],
            ],
            'externalCode' => (string) $order->id,
            'positions' => $positions,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ])->post("{$this->baseURL}/entity/demand", $payload);

        if (! $response->successful()) {
            Log::error('MoySklad: ошибка создания отгрузки (demand)', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception(
                'МойСклад: не удалось создать отгрузку. ' . ($response->json('errors.0.error') ?? $response->body())
            );
        }

        $demandUuid = $response->json('id');

        $order->forceFill(['moysklad_demand_uuid' => $demandUuid])->save();

        Log::info('MoySklad: отгрузка (demand) создана', [
            'order_id' => $order->id,
            'demand_uuid' => $demandUuid,
        ]);

        return $demandUuid;
    }

    /**
     * Вернуть товар на склад: распровести существующий demand
     * (applicable=false). Документ не удаляется — только откатывается
     * списание. Идемпотентно — если demand ещё не создавался, ничего не
     * делает.
     *
     * @throws Exception
     */
    public function returnOrderStock(Order $order): void
    {
        if (! $order->moysklad_demand_uuid) {
            return;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ])->put("{$this->baseURL}/entity/demand/{$order->moysklad_demand_uuid}", [
            'applicable' => false,
        ]);

        if (! $response->successful()) {
            Log::error('MoySklad: ошибка распроведения отгрузки (demand) при возврате товара', [
                'order_id' => $order->id,
                'demand_uuid' => $order->moysklad_demand_uuid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception(
                'МойСклад: не удалось вернуть товар на склад. ' . ($response->json('errors.0.error') ?? $response->body())
            );
        }

        Log::info('MoySklad: товар возвращён на склад (demand распроведён)', [
            'order_id' => $order->id,
            'demand_uuid' => $order->moysklad_demand_uuid,
        ]);
    }

    /**
     * Получить meta первого склада аккаунта. В проекте на текущий момент
     * используется один склад («Основной склад») — если их станет
     * несколько, здесь понадобится явный выбор склада по заказу/настройкам.
     *
     * @throws Exception
     */
    private function getDefaultStoreMeta(): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get("{$this->baseURL}/entity/store", ['limit' => 1]);

        if (! $response->successful() || empty($response->json('rows'))) {
            throw new Exception('МойСклад: не удалось получить склад (store).');
        }

        return $response->json('rows.0.meta');
    }

    /**
     * Позиции для документа demand: та же схема, что и для customerorder,
     * но без discount/reserve — они на demand не применяются так же.
     */
    private function buildPositions(Order $order): array
    {
        $positions = [];

        foreach ($order->items as $item) {
            $variantUuid = $item->variant?->uuid ?? null;

            if (! $variantUuid) {
                Log::warning('MoySklad: позиция пропущена при создании отгрузки — нет uuid варианта', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                ]);
                continue;
            }

            $positions[] = [
                'assortment' => [
                    'meta' => [
                        'href' => "{$this->baseURL}/entity/variant/{$variantUuid}",
                        'type' => 'variant',
                        'mediaType' => 'application/json',
                    ],
                ],
                'price' => (int) round((float) $item->price * 100),
                'quantity' => (float) $item->quantity,
            ];
        }

        return $positions;
    }
}
