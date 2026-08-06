<?php

namespace App\Services\Delivery;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\YandexOrder;
use App\Models\YandexStatusEvent;
use App\Services\Delivery\Yandex\PayloadBuilder;
use App\Services\Delivery\Yandex\StatusMapper;
use App\Services\Delivery\Yandex\YandexDeliveryClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** Yandex Delivery Platform API (Delivery across Russia / NDD). */
class YandexDeliveryService extends DeliveryService
{
    protected array $settings;
    private YandexDeliveryClient $client;

    public function __construct(array $methodSettings = [], private ?PayloadBuilder $payloadBuilder = null, private ?StatusMapper $statusMapper = null)
    {
        $database = DeliveryServiceSetting::query()->where('service_name', 'yandex')->value('settings') ?? [];
        $config = config('services.yandex_delivery');
        $this->settings = array_replace_recursive($config, $database, $methodSettings);
        $mode = $this->settings['mode'] ?? 'sandbox';
        $this->settings['base_url'] = $this->settings['base_url'][$mode] ?? null;
        $this->payloadBuilder ??= app(PayloadBuilder::class);
        $this->statusMapper ??= app(StatusMapper::class);
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
        return $this->client->request('GET', '/api/b2b/platform/request/info', [], $orderId, $requestId, ['request_id' => $requestId, 'slim' => 'true']);
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
        ], fn ($value) => $value !== null);
        $result = $this->client->request('POST', '/api/b2b/platform/pickup-points/list', $payload);
        return $result['successful'] ? collect($result['data']['points'] ?? []) : collect();
    }

    public function detectLocation(string $location): array
    {
        $result = $this->client->request('POST', '/api/b2b/platform/location/detect', ['location' => $location]);
        return $result['successful'] ? $result['data'] : [];
    }

    public function geocode(string $address): ?array
    {
        $key = $this->settings['geocoder_key'] ?? null;
        if (! $key) return null;
        $response = Http::timeout(10)->get('https://geocode-maps.yandex.ru/1.x/', ['apikey' => $key, 'geocode' => $address, 'format' => 'json', 'results' => 1]);
        $point = $response->json('response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos');
        return $point ? array_map('floatval', explode(' ', $point)) : null;
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
        $yandexOrder = YandexOrder::updateOrCreate(['order_id' => $order->id], [
            'claim_id' => $requestId, 'status' => $status, 'internal_status' => $this->statusMapper->toInternal($status),
            'delivery_type' => $delivery['delivery_type'] ?? 'courier', 'tariff_code' => $delivery['tariff_code'] ?? null,
            'price' => $delivery['price'] ?? null, 'offer_id' => $delivery['offer_id'] ?? null,
            'pvz_id' => $delivery['pvz']['id'] ?? null, 'tracking_url' => $data['sharing_url'] ?? null,
            'request_id' => $existing?->request_id ?? (string) \Illuminate\Support\Str::uuid(), 'last_synced_at' => now(),
        ]);
        if ($requestId && $order->tracking_number !== $requestId) {
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
                'delivery_date' => $intervalFrom ?? $intervalTo ?? $offer['delivery_date'] ?? null,
                'delivery_interval' => $intervalFrom || $intervalTo ? [
                    'from' => $intervalFrom,
                    'to' => $intervalTo,
                ] : null,
            ];
        })->filter(fn (array $offer) => $offer['offer_id'])->values()->all();
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
