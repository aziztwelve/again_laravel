<?php

namespace App\Services\Delivery;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\YandexOrder;
use App\Models\YandexTariff;
use App\Services\Delivery\Yandex\PayloadBuilder;
use App\Services\Delivery\Yandex\StatusMapper;
use App\Services\Delivery\Yandex\YandexDeliveryClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** NDD Express Delivery API (offers/calculate → claims/*). */
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

    public function calculateOffers(string $deliveryType, array $items, ?string $pvzId = null, ?array $pvzCoords = null, ?array $destination = null, array $recipient = [], ?string $tariffCode = null): array
    {
        try {
            $tariff = $tariffCode ? YandexTariff::query()->where('code', $tariffCode)->where('is_active', true)->first() : null;
            $payload = $this->payloadBuilder->offers($this->source(), $deliveryType, $items, $destination, $pvzCoords, $tariff?->taxi_class);
        } catch (InvalidArgumentException $exception) {
            Log::notice('Yandex Delivery offer calculation rejected', ['message' => $exception->getMessage()]);
            return [];
        }

        $result = $this->client->request('POST', 'offers/calculate', $payload);
        if (!$result['successful']) {
            Log::warning('Yandex Delivery offers/calculate failed', ['status' => $result['status'], 'response' => $result['data']]);
            return [];
        }

        return $this->normalizeOffers($result['data']['offers'] ?? $result['data']);
    }

    /** @return array{successful: bool, status: int, data: array, body: string} */
    public function createClaim(Order $order, ?YandexOrder $yandexOrder = null): array
    {
        $yandexOrder ??= $order->yandexOrder;
        $requestId = $yandexOrder?->request_id ?? (string) Str::uuid();
        $payload = $this->payloadBuilder->claim($order->loadMissing('items.product', 'items.variant', 'client'), $this->source());
        $result = $this->client->request('POST', 'claims/create', $payload, $order->id, $yandexOrder?->claim_id, ['request_id' => $requestId]);

        if ($result['successful']) {
            $claim = $result['data'];
            YandexOrder::updateOrCreate(['order_id' => $order->id], [
                'claim_id' => $claim['id'] ?? $claim['claim_id'] ?? $yandexOrder?->claim_id,
                'claim_version' => $claim['version'] ?? 1,
                'status' => $claim['status'] ?? 'new',
                'internal_status' => $this->statusMapper->toInternal($claim['status'] ?? 'new'),
                'delivery_type' => $order->delivery_data['delivery_type'] ?? 'courier',
                'tariff_code' => $order->delivery_data['tariff_code'] ?? null,
                'price' => $order->delivery_data['price'] ?? null,
                'offer_id' => $order->delivery_data['offer_id'] ?? null,
                'pvz_id' => $order->delivery_data['pvz']['id'] ?? null,
                'scheduled_time' => $order->delivery_data['scheduled_time'] ?? null,
                'request_id' => $requestId,
                'last_synced_at' => now(),
            ]);
        }

        return $result;
    }

    public function getClaimInfo(string $claimId, ?int $orderId = null): array
    {
        return $this->client->request('POST', 'claims/info', ['claim_id' => $claimId], $orderId, $claimId);
    }

    public function acceptClaim(string $claimId, int $version, ?int $orderId = null): array
    {
        return $this->client->request('POST', 'claims/accept', ['claim_id' => $claimId, 'version' => $version], $orderId, $claimId);
    }

    public function cancelClaim(string $claimId, int $version, string $cancelState = 'free', ?int $orderId = null): array
    {
        return $this->client->request('POST', 'claims/cancel', ['claim_id' => $claimId, 'version' => $version, 'cancel_state' => $cancelState], $orderId, $claimId);
    }

    public function getPerformerPosition(string $claimId, ?int $orderId = null): array
    {
        return $this->client->request('GET', 'claims/performer-position', [], $orderId, $claimId, ['claim_id' => $claimId]);
    }

    public function getDriverPhone(string $claimId, ?int $orderId = null): array
    {
        return $this->client->request('POST', 'driver-voiceforwarding', ['claim_id' => $claimId], $orderId, $claimId);
    }

    public function geocode(string $address): ?array
    {
        $key = $this->settings['geocoder_key'] ?? null;
        if (!$key) return null;
        $response = Http::timeout(10)->get('https://geocode-maps.yandex.ru/1.x/', ['apikey' => $key, 'geocode' => $address, 'format' => 'json', 'results' => 1]);
        $point = $response->json('response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos');
        return $point ? array_map('floatval', explode(' ', $point)) : null;
    }

    /** Legacy endpoint: NDD Express has no pickup-points/list equivalent; the official widget is used instead. */
    public function getPickupPoints(array $filter = []): Collection { return collect(); }
    public function detectLocation(string $location): array { return []; }

    public function calculateRate(Order $order): Collection
    {
        $data = $order->delivery_data ?? [];
        return collect($this->calculateOffers($data['delivery_type'] ?? 'courier', [], $data['pvz']['id'] ?? null, $data['pvz']['coordinates'] ?? null, $data['destination'] ?? null, [], $data['tariff_code'] ?? null));
    }

    public function createShipment(Order $order): Shipment
    {
        $result = $this->createClaim($order);
        if (!$result['successful']) throw new \RuntimeException('Не удалось создать заявку Яндекс.Доставки.');
        $claimId = $result['data']['id'] ?? $result['data']['claim_id'] ?? null;
        $statusId = ShipmentStatus::query()->where('code', ShipmentStatus::NEW)->value('id');
        return Shipment::updateOrCreate(['order_id' => $order->id], [
            'delivery_method_id' => $order->delivery_method_id,
            'status_id' => $statusId,
            'tracking_number' => $claimId,
            'provider_data' => $result['data'],
            'shipping_address' => json_encode($order->delivery_address, JSON_UNESCAPED_UNICODE),
            'recipient_name' => $order->client?->name ?? 'Покупатель',
            'recipient_phone' => $order->client?->phone ?? '',
            'cost' => $order->delivery_cost ?? 0,
        ]);
    }

    public function getTrackingInfo(string $trackingNumber): array { return $this->getClaimInfo($trackingNumber)['data']; }
    public function cancelShipment(Shipment $shipment): bool
    {
        $yandexOrder = YandexOrder::query()->where('shipment_id', $shipment->id)->first();
        if (!$yandexOrder?->claim_id) return false;
        return $this->cancelClaim($yandexOrder->claim_id, $yandexOrder->claim_version, 'free', $shipment->order_id)['successful'];
    }
    public function printLabel(Shipment $shipment): string { return ''; }

    private function source(): array
    {
        $source = $this->settings['source'] ?? [];
        if (empty($source['coordinates']) || empty($source['address'])) throw new InvalidArgumentException('Не настроена точка отправки Яндекс.Доставки.');
        return $source;
    }

    private function normalizeOffers(array $offers): array
    {
        return collect($offers)->map(function (array $offer) {
            $code = $offer['tariff_code'] ?? $offer['taxi_class'] ?? $offer['requirements']['taxi_class'] ?? null;
            $tariff = $code ? YandexTariff::query()->where('code', $code)->first() : null;
            return [
                'offer_id' => $offer['offer_id'] ?? $offer['id'] ?? null,
                'tariff_code' => $code,
                'title' => $tariff?->title ?? $offer['tariff_name'] ?? $code ?? 'Яндекс.Доставка',
                'price' => (float) ($offer['price'] ?? $offer['price_info']['total_price'] ?? 0),
                'currency' => $offer['currency'] ?? 'RUB',
                'delivery_date' => $offer['delivery_date'] ?? $offer['delivery_interval']['to'] ?? null,
                'delivery_interval' => $offer['delivery_interval'] ?? null,
                'slots' => $offer['slots'] ?? [],
            ];
        })->values()->all();
    }
}
