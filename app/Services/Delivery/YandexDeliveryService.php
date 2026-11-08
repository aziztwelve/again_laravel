<?php

namespace App\Services\Delivery;

use App\Models\DeliveryMethod;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\YandexOrder;
use App\Models\YandexStatusEvent;
use App\Services\Delivery\Yandex\PayloadBuilder;
use App\Services\Delivery\Yandex\CustomerStatusMapper;
use App\Services\Delivery\Yandex\StatusMapper;
use App\Services\Delivery\Yandex\YandexDeliveryClient;
use App\Services\Notifications\YandexDeliveryNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** Yandex Delivery Platform API (Delivery across Russia / NDD). */
class YandexDeliveryService extends DeliveryService
{
    protected array $settings;
    private YandexDeliveryClient $client;

    public function __construct(
        array $methodSettings = [],
        private ?PayloadBuilder $payloadBuilder = null,
        private ?StatusMapper $statusMapper = null,
        private ?CustomerStatusMapper $customerStatusMapper = null,
        private ?YandexDeliveryNotificationService $notificationService = null,
    )
    {
        $database = DeliveryServiceSetting::query()->where('service_name', 'yandex')->value('settings') ?? [];
        $config = config('services.yandex_delivery');
        $this->settings = array_replace_recursive($config, $database, $methodSettings);
        $mode = $this->settings['mode'] ?? 'sandbox';
        $customBaseUrl = trim((string) ($this->settings['api_url'] ?? ''));
        $this->settings['base_url'] = $customBaseUrl !== ''
            ? $customBaseUrl
            : ($this->settings['base_url'][$mode] ?? null);
        $this->payloadBuilder ??= app(PayloadBuilder::class);
        $this->statusMapper ??= app(StatusMapper::class);
        $this->customerStatusMapper ??= app(CustomerStatusMapper::class);
        $this->notificationService ??= app(YandexDeliveryNotificationService::class);
        $this->client = new YandexDeliveryClient($this->settings);
    }

    public function calculateOffers(string $deliveryType, array $items, ?string $pvzId = null, ?array $pvzCoords = null, ?array $destination = null, array $recipient = []): array
    {
        try {
            $payload = $this->payloadBuilder->offers($this->settings, $deliveryType, $items, $pvzId, $destination, $recipient);
        } catch (InvalidArgumentException $exception) {
            Log::notice('Yandex Delivery offer calculation rejected', ['message' => $exception->getMessage()]);
            return [];
        }

        $result = $this->client->request('POST', '/api/b2b/platform/offers/create', $payload, query: ['send_unix' => 'false']);
        if (! $result['successful']) {
            Log::warning('Yandex Delivery offers/create failed', ['status' => $result['status'], 'response' => $result['data']]);
            if (
                (int) $result['status'] === 400
                && data_get($result['data'], 'code') === 'validation_error'
                && data_get($result['data'], 'message') === "Recipient's phone is invalid"
            ) {
                throw new InvalidArgumentException(
                    'Яндекс.Доставка не приняла номер получателя. Проверьте номер и укажите действующий российский мобильный номер.'
                );
            }
            return [];
        }
        $offers = $this->normalizeOffers($result['data']['offers'] ?? []);
        $itemsTotal = array_sum(array_map(fn (array $item) => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1), $items));
        $freeFrom = $deliveryType === 'courier' ? 7900 : 4500;
        if ($itemsTotal >= $freeFrom) {
            $offers = array_map(function (array $offer) {
                $offer['provider_price'] = $offer['price'];
                $offer['price'] = 0.0;
                return $offer;
            }, $offers);
        }
        return $offers;
    }

    /**
     * Данные доставки Яндекса для заказа, пришедшего без цены (ручной заказ из
     * админки отправляет только пункт выдачи). Без цены доставка получалась
     * бесплатной, хотя правило бесплатной доставки не срабатывало: цену считаем
     * на сервере по предложениям Яндекса.
     *
     * Идентификатор предложения в заказ не пишем: оно живёт недолго, а после
     * оплаты заказ без offer_id создаётся прямой заявкой. Заказ не блокируем:
     * если цену получить не удалось, данные возвращаются как есть.
     *
     * @param  array  $payload  валидированные данные заказа
     * @param  array<int, array>  $items  проверенные позиции заказа (validated_items)
     * @return array  delivery_data для заказа
     */
    public function completeDelivery(array $payload, array $items): array
    {
        $data = is_array($payload['delivery_data'] ?? null) ? $payload['delivery_data'] : [];

        // Цена уже есть (в том числе нулевая от правила витрины) или это не Яндекс.
        if (array_key_exists('price', $data) || ($data['provider'] ?? 'yandex') !== 'yandex') {
            return $data;
        }

        try {
            $methodCode = $this->resolveMethodCode($payload);
            if (! in_array($methodCode, ['yandex_pickup', 'yandex_courier'], true) && ($data['provider'] ?? null) !== 'yandex') {
                return $data;
            }

            $type = match ($methodCode) {
                'yandex_courier' => 'courier',
                'yandex_pickup' => 'pickup',
                default => in_array($payload['delivery_type'] ?? $data['delivery_type'] ?? null, ['pickup', 'courier'], true)
                    ? ($payload['delivery_type'] ?? $data['delivery_type'])
                    : 'pickup',
            };

            $pvzId = $payload['pvz_code'] ?? $data['pvz']['id'] ?? null;
            $destination = null;
            if ($type === 'courier') {
                $address = is_array($payload['delivery_address'] ?? null) ? $payload['delivery_address'] : [];
                $coordinates = $payload['destination_coordinates'] ?? $data['destination']['coordinates'] ?? null;
                $destination = array_filter([
                    'address' => implode(', ', array_filter([$address['city'] ?? null, $address['address'] ?? null])),
                    'coordinates' => is_array($coordinates) && count($coordinates) === 2 ? $coordinates : null,
                ], fn ($value) => $value !== null);
            }

            $offers = $this->calculateOffers(
                $type,
                array_map(fn (array $item) => $this->offerItem($item), $items),
                $pvzId,
                null,
                $destination,
                // Яндекс проверяет формат телефона, поэтому берём настоящего получателя заказа.
                [
                    'name' => trim(($payload['recipient']['first_name'] ?? '').' '.($payload['recipient']['last_name'] ?? '')) ?: 'Покупатель',
                    'phone' => (string) ($payload['recipient']['phone'] ?? ''),
                ],
            );

            // Берём тарифную цену Яндекса: provider_price сохраняется, когда
            // calculateOffers уже обнулил цену по собственному порогу.
            $prices = array_map(fn (array $offer) => (float) ($offer['provider_price'] ?? $offer['price'] ?? 0), $offers);
            $prices = array_filter($prices, fn (float $price) => $price > 0);

            if ($prices === []) {
                Log::warning('Yandex Delivery: price was not calculated for an order without price', [
                    'delivery_type' => $type,
                    'pvz_id' => $pvzId,
                ]);

                return $data;
            }

            return [...$data, 'delivery_type' => $type, 'price' => min($prices)];
        } catch (\Throwable $exception) {
            Log::warning('Yandex Delivery: failed to complete delivery data', ['error' => $exception->getMessage()]);

            return $data;
        }
    }

    /** Код способа доставки по имени из заказа (yandex_pickup / yandex_courier). */
    protected function resolveMethodCode(array $payload): ?string
    {
        $name = $payload['delivery_method']['name'] ?? null;

        return $name ? DeliveryMethod::query()->where('name', $name)->value('code') : null;
    }

    /** Позиция заказа в формате расчёта предложений (как её отправляет витрина). */
    private function offerItem(array $item): array
    {
        $model = $item['model'] ?? null;
        $product = $model?->product;

        return [
            'name' => $item['name'] ?? $model?->name ?? $product?->name ?? 'Товар',
            'article' => (string) ($model?->sku ?? $product?->sku ?? $model?->id ?? ''),
            'weight' => ($model?->weight ?: $product?->weight) ?: 500,
            'size' => [
                'length' => $model?->length ?: $product?->length,
                'width' => $model?->width ?: $product?->width,
                'height' => $model?->height ?: $product?->height,
            ],
            'quantity' => (int) ($item['quantity'] ?? 1),
            'price' => (float) ($item['final_price'] ?? 0),
        ];
    }

    public function confirmOffer(string $offerId, ?int $orderId = null): array
    {
        return $this->client->request('POST', '/api/b2b/platform/offers/confirm', ['offer_id' => $offerId], $orderId);
    }

    public function confirmOfferForOrder(Order $order, YandexOrder $yandexOrder): array
    {
        $offerId = $order->delivery_data['offer_id'] ?? null;
        if (! $offerId) return $this->createOrder($order, $yandexOrder);
        $result = $this->confirmOffer($offerId, $order->id);
        if ($result['successful']) $this->saveRequest($order, $result['data'], $yandexOrder);
        return $result;
    }

    /** Creates an immediate Platform API request as a fallback for an already paid order. */
    public function createOrder(Order $order, ?YandexOrder $yandexOrder = null): array
    {
        $yandexOrder ??= $order->yandexOrder;
        $payload = $this->payloadBuilder->order($order, $this->settings);
        $result = $this->client->request('POST', '/api/b2b/platform/request/create', $payload, $order->id, $yandexOrder?->claim_id, ['send_unix' => 'false']);
        if ($result['successful']) $this->saveRequest($order, $result['data'], $yandexOrder);
        return $result;
    }

    public function getRequestInfo(string $requestId, ?int $orderId = null): array
    {
        // Полный ответ содержит sharing_url для покупательского трекинга.
        // Обновлённый slim-ответ Яндекса это поле не возвращает.
        return $this->client->request('GET', '/api/b2b/platform/request/info', [], $orderId, $requestId, ['request_id' => $requestId, 'slim' => 'false']);
    }

    public function cancelRequest(string $requestId, ?int $orderId = null): array
    {
        return $this->client->request('POST', '/api/b2b/platform/request/cancel', ['request_id' => $requestId], $orderId, $requestId);
    }

    public function getPickupPoints(array $filter = []): Collection
    {
        $payload = array_filter([
            'geo_id' => $filter['geo_id'] ?? null,
            'type' => $filter['type'] ?? 'pickup_point',
            'payment_method' => $filter['payment_method'] ?? 'already_paid',
            'is_yandex_branded' => $filter['is_yandex_branded'] ?? null,
            'latitude' => isset($filter['latitude_from'], $filter['latitude_to']) ? [
                'from' => (float) $filter['latitude_from'],
                'to' => (float) $filter['latitude_to'],
            ] : null,
            'longitude' => isset($filter['longitude_from'], $filter['longitude_to']) ? [
                'from' => (float) $filter['longitude_from'],
                'to' => (float) $filter['longitude_to'],
            ] : null,
        ], fn ($value) => $value !== null);
        // Список пунктов выдачи тяжёлый (до десятков МБ) и меняется редко: на каждое
        // открытие карты в оформлении его запрашивать не нужно. Кэш на 30 минут в
        // файловом хранилище (кэш по умолчанию лежит в базе, большие значения туда
        // не кладём). Неудачные ответы не кэшируем.
        $cache = Cache::store('file');
        $cacheKey = 'yandex:pvz:'.md5(json_encode($payload));
        $cached = $cache->get($cacheKey);
        if (is_array($cached)) {
            return collect($cached);
        }

        $result = $this->client->request('POST', '/api/b2b/platform/pickup-points/list', $payload);
        if (! $result['successful']) {
            return collect();
        }

        $points = $result['data']['points'] ?? [];
        $cache->put($cacheKey, $points, now()->addMinutes(30));

        return collect($points);
    }

    public function detectLocation(string $location): array
    {
        $result = $this->client->request('POST', '/api/b2b/platform/location/detect', ['location' => $location]);
        return $result['successful'] ? $result['data'] : [];
    }

    public function geocode(string $address): ?array
    {
        $key = $this->settings['geocoder_key'] ?? null;
        $address = trim($address);
        if (! $key || $address === '') return null;

        // Дневной лимит геокодера мал (1000/сутки): успех кэшируем на сутки, неудачу — на 10 минут.
        $hash = md5(mb_strtolower($address));
        if (($cached = Cache::get("yandex:geocode:$hash")) !== null) return $cached;
        if (Cache::has("yandex:geocode:fail:$hash")) return null;

        try {
            $response = Http::timeout(10)->get('https://geocode-maps.yandex.ru/1.x/', ['apikey' => $key, 'geocode' => $address, 'format' => 'json', 'results' => 1]);
            $point = $response->successful() ? $response->json('response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos') : null;
        } catch (\Throwable) {
            $point = null;
        }

        if (! $point) {
            Cache::put("yandex:geocode:fail:$hash", 1, now()->addMinutes(10));
            return null;
        }
        $coordinates = array_map('floatval', explode(' ', $point));
        Cache::put("yandex:geocode:$hash", $coordinates, now()->addDay());
        return $coordinates;
    }

    public function sync(YandexOrder $yandexOrder): void
    {
        if (! $yandexOrder->claim_id) return;
        $result = $this->getRequestInfo($yandexOrder->claim_id, $yandexOrder->order_id);
        if (! $result['successful']) return;
        $this->saveRequest($yandexOrder->order, $result['data'], $yandexOrder);
    }

    public function createShipment(Order $order): Shipment
    {
        $result = $this->createOrder($order);
        if (! $result['successful']) throw new \RuntimeException('Не удалось создать заявку Яндекс.Доставки.');
        $requestId = $result['data']['request_id'] ?? null;
        $statusId = ShipmentStatus::query()->where('code', ShipmentStatus::NEW)->value('id');
        $address = $order->loadMissing('address')->address;
        $recipientName = trim(implode(' ', array_filter([
            $address?->recipient_first_name,
            $address?->recipient_middle_name,
            $address?->recipient_last_name,
        ])));
        return Shipment::updateOrCreate(['order_id' => $order->id], [
            'delivery_method_id' => $order->delivery_method_id, 'status_id' => $statusId,
            'tracking_number' => $requestId, 'provider_data' => $result['data'],
            'shipping_address' => json_encode($order->delivery_address, JSON_UNESCAPED_UNICODE),
            'recipient_name' => $recipientName ?: ($order->client?->name ?? 'Покупатель'),
            'recipient_phone' => $address?->recipient_phone ?? $order->client?->phone ?? '',
            'cost' => $order->delivery_cost ?? 0,
        ]);
    }

    public function calculateRate(Order $order): Collection { return collect(); }
    public function getTrackingInfo(string $trackingNumber): array { return $this->getRequestInfo($trackingNumber)['data']; }
    public function cancelShipment(Shipment $shipment): bool { return $this->cancelRequest($shipment->tracking_number, $shipment->order_id)['successful']; }
    public function printLabel(Shipment $shipment): string { return ''; }

    private function saveRequest(Order $order, array $data, ?YandexOrder $existing = null): YandexOrder
    {
        $requestId = $data['request_id'] ?? $existing?->claim_id;
        $state = $data['state'] ?? [];
        $status = $state['status'] ?? $data['status'] ?? 'CREATED';
        $delivery = $order->delivery_data ?? [];
        $previousStatus = $existing?->status;
        $previousCustomerStatus = $existing?->customer_status;
        $customerStatus = $this->customerStatusMapper->toCustomer($status);
        $trackingUrl = $data['sharing_url'] ?? $data['tracking_url'] ?? $existing?->tracking_url;
        $trackingNumber = $data['tracking_number'] ?? $data['barcode'] ?? $existing?->tracking_number;
        $yandexOrder = YandexOrder::updateOrCreate(['order_id' => $order->id], [
            'claim_id' => $requestId, 'status' => $status, 'internal_status' => $this->statusMapper->toInternal($status),
            'customer_status' => $customerStatus ?? $previousCustomerStatus,
            'delivery_type' => $delivery['delivery_type'] ?? 'courier', 'tariff_code' => $delivery['tariff_code'] ?? null,
            'price' => $delivery['price'] ?? null, 'offer_id' => $delivery['offer_id'] ?? null,
            'pvz_id' => $delivery['pvz']['id'] ?? null, 'tracking_url' => $trackingUrl,
            'tracking_number' => $trackingNumber,
            'request_id' => $existing?->request_id ?? (string) \Illuminate\Support\Str::uuid(), 'last_synced_at' => now(),
        ]);
        // Храним данные Яндекс.Доставки под отдельными ключами. Общие
        // tracking_url/tracking_number могут быть заполнены СДЭК и не должны
        // попадать в блок Яндекс.Доставки.
        unset($delivery['tracking_url'], $delivery['tracking_number']);
        $updatedDelivery = array_merge($delivery, [
            'yandex_claim_id' => $requestId,
            'yandex_tracking_url' => $trackingUrl,
        ]);
        if ($trackingNumber) {
            $updatedDelivery['yandex_tracking_number'] = $trackingNumber;
        }
        if ($order->delivery_data !== $updatedDelivery) {
            $order->update(['delivery_data' => $updatedDelivery]);
        }
        // Трек, вписанный вручную (например, ID у партнёра по доставке LO-…),
        // опрос статусов не перезаписывает: подставляем ID заявки только в
        // пустой трек или тот, что сам был равен ID заявки.
        if ($requestId && $order->tracking_number !== $requestId
            && (blank($order->tracking_number) || $order->tracking_number === $existing?->claim_id)) {
            $order->update(['tracking_number' => $requestId]);
        }
        if ($previousStatus !== $status) {
            YandexStatusEvent::create([
                'yandex_order_id' => $yandexOrder->id,
                'source' => 'polling',
                'raw_status' => $status,
                'internal_status' => $this->statusMapper->toInternal($status),
                'payload' => $data,
                'received_at' => now(),
            ]);
        }
        if ($customerStatus !== null && $previousCustomerStatus !== $customerStatus) {
            try {
                if ($customerStatus !== 'delivery_created') {
                    $this->notificationService->notify($yandexOrder->fresh('order'));
                }
            } catch (\Throwable $exception) {
                Log::error('Failed to queue Yandex delivery customer notification', [
                    'order_id' => $order->id,
                    'yandex_order_id' => $yandexOrder->id,
                    'customer_status' => $customerStatus,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        return $yandexOrder;
    }

    private function normalizeOffers(array $offers): array
    {
        return collect($offers)->map(function (array $offer): array {
            // Platform API returns these fields inside offer_details. The older
            // response shape used the top-level pricing/details keys, so keep it
            // as a fallback for already supported environments.
            $details = is_array($offer['offer_details'] ?? null) ? $offer['offer_details'] : [];
            $pricing = $details['pricing_total']
                ?? $details['pricing']
                ?? data_get($offer, 'pricing.total')
                ?? $offer['price']
                ?? 0;
            $deliveryInterval = $details['delivery_interval'] ?? $offer['delivery_interval'] ?? null;
            $intervalFrom = data_get($deliveryInterval, 'from') ?? data_get($deliveryInterval, 'min');
            $intervalTo = data_get($deliveryInterval, 'to') ?? data_get($deliveryInterval, 'max');

            return [
                'offer_id' => $offer['offer_id'] ?? $offer['id'] ?? null,
                'tariff_name' => $details['tariff_name'] ?? data_get($offer, 'details.tariff_name') ?? $offer['tariff_name'] ?? 'Яндекс.Доставка',
                'price' => $this->priceFromApiValue($pricing),
                'currency' => $details['currency'] ?? data_get($offer, 'pricing.currency') ?? 'RUB',
                // Platform API uses min/max; keep a stable from/to contract for
                // the checkout and derive the displayed delivery day from it.
                'delivery_date' => $this->applyDeliveryDateOffset($intervalFrom ?? $intervalTo ?? $offer['delivery_date'] ?? null),
                'delivery_interval' => $intervalFrom || $intervalTo ? [
                    'from' => $this->applyDeliveryDateOffset($intervalFrom),
                    'to' => $this->applyDeliveryDateOffset($intervalTo),
                ] : null,
            ];
        })->filter(fn (array $offer) => $offer['offer_id'])->values()->all();
    }

    /** Adds the configured operational buffer to a date supplied by Platform API. */
    private function applyDeliveryDateOffset(?string $date): ?string
    {
        if (blank($date)) return $date;

        $days = max(0, (int) ($this->settings['delivery_date_offset_days'] ?? 2));
        if ($days === 0) return $date;

        try {
            return Carbon::parse($date)->addDays($days)->toIso8601String();
        } catch (\Throwable) {
            return $date;
        }
    }

    /** Converts Platform API values such as "402.6 RUB" to a ruble amount. */
    private function priceFromApiValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value) || ! preg_match('/[-+]?\d+(?:[.,]\d+)?/', $value, $matches)) {
            return 0.0;
        }

        return (float) str_replace(',', '.', $matches[0]);
    }
}
