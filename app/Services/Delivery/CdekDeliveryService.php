<?php

namespace App\Services\Delivery;

use App\Models\CdekOrder;
use App\Models\CdekStatusEvent;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Services\Delivery\Cdek\CdekClient;
use App\Services\Notifications\CdekDeliveryNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CdekDeliveryService extends DeliveryService
{
    private CdekClient $client;

    public function __construct(array $methodSettings = [], private ?CdekDeliveryNotificationService $notificationService = null)
    {
        $database = DeliveryServiceSetting::query()->where('service_name', 'cdek')->value('settings') ?? [];
        $this->settings = array_replace_recursive(config('services.cdek_delivery'), $database, $methodSettings);
        $this->notificationService ??= app(CdekDeliveryNotificationService::class);
        $this->client = new CdekClient($this->settings);
    }

    public function cities(string $query, string $countryCode = 'RU'): array
    {
        $result = $this->client->request('GET', '/v2/location/suggest/cities', query: [
            'name' => $query, 'country_code' => strtoupper($countryCode),
        ]);
        return $result['successful'] ? ($result['data'] ?? []) : [];
    }

    public function pickupPoints(array $filter = []): array
    {
        $query = array_filter([
            'city_code' => $filter['city_code'] ?? null,
            'country_code' => isset($filter['country_code']) ? strtoupper($filter['country_code']) : 'RU',
            'type' => $filter['type'] ?? 'ALL',
            'is_handout' => $filter['is_handout'] ?? true,
        ], fn ($value) => $value !== null && $value !== '');
        $result = $this->client->request('GET', '/v2/deliverypoints', query: $query);
        return $result['successful'] ? ($result['data'] ?? []) : [];
    }

    public function calculateTariffs(string $deliveryType, array $destination, array $items, ?string $pickupPoint = null): array
    {
        $this->assertSenderConfigured();
        if ($deliveryType === 'pickup' && ! $pickupPoint) throw new InvalidArgumentException('Выберите пункт выдачи СДЭК.');
        if ($deliveryType === 'courier' && (! filled($destination['city_code'] ?? null) || ! filled($destination['address'] ?? null))) {
            throw new InvalidArgumentException('Для курьерской доставки укажите город СДЭК и адрес.');
        }

        $payload = [
            'type' => (int) ($this->settings['order_type'] ?? 1),
            'currency' => 643,
            'lang' => 'rus',
            'from_location' => $this->senderLocation(),
            'to_location' => $deliveryType === 'courier' ? [
                'code' => (int) $destination['city_code'],
                'address' => $destination['address'],
            ] : ['code' => (int) ($destination['city_code'] ?? 0)],
            'packages' => [$this->package($items, 'quote')],
        ];
        if ($deliveryType === 'pickup') $payload['delivery_point'] = $pickupPoint;

        $result = $this->client->request('POST', '/v2/calculator/tarifflist', $payload);
        if (! $result['successful']) return [];

        $deliveryMode = $deliveryType === 'courier' ? 1 : 2;
        return collect($result['data']['tariff_codes'] ?? [])
            ->filter(fn (array $tariff) => (int) ($tariff['delivery_mode'] ?? 0) === $deliveryMode)
            ->map(fn (array $tariff) => [
            'tariff_code' => $tariff['tariff_code'],
            'tariff_name' => $tariff['tariff_name'],
            'delivery_mode' => $tariff['delivery_mode'],
            'price' => (float) $tariff['delivery_sum'],
            'currency' => 'RUB',
            'period' => ['min' => $tariff['period_min'], 'max' => $tariff['period_max']],
            'delivery_date_range' => $tariff['delivery_date_range'] ?? null,
            ])->values()->all();
    }

    public function createExternalOrder(Order $order, CdekOrder $cdekOrder): array
    {
        $this->assertSenderConfigured();
        $delivery = $order->delivery_data ?? [];
        $tariff = $delivery['tariff_code'] ?? null;
        if (! $tariff) throw new InvalidArgumentException('Не выбран тариф СДЭК.');

        $address = $order->loadMissing('address')->address;
        $recipientName = trim(implode(' ', array_filter([$address?->recipient_last_name, $address?->recipient_first_name, $address?->recipient_middle_name]))) ?: 'Покупатель';
        $recipientPhone = $address?->recipient_phone ?? $order->client?->phone;
        if (! $recipientPhone) throw new InvalidArgumentException('Для оформления СДЭК нужен телефон получателя.');
        $destination = $delivery['destination'] ?? [];
        $isPickup = ($delivery['delivery_type'] ?? null) === 'pickup';
        $payload = [
            'type' => (int) ($this->settings['order_type'] ?? 1),
            'number' => $cdekOrder->external_order_number,
            'tariff_code' => (int) $tariff,
            'recipient' => ['name' => $recipientName, 'phones' => [['number' => $recipientPhone]]],
            'from_location' => $this->senderLocation(),
            'packages' => [$this->package($this->orderItems($order), $cdekOrder->external_order_number)],
        ];
        if ($isPickup) $payload['delivery_point'] = $delivery['pvz']['code'] ?? $delivery['pvz_code'] ?? null;
        else $payload['to_location'] = ['code' => (int) ($destination['city_code'] ?? 0), 'address' => $destination['address'] ?? $address?->address];
        if (empty($payload['delivery_point']) && $isPickup) throw new InvalidArgumentException('Не выбран ПВЗ СДЭК.');

        return $this->client->request('POST', '/v2/orders', $payload, cdekOrderId: $cdekOrder->id);
    }

    public function sync(CdekOrder $cdekOrder): void
    {
        $result = $this->client->request('GET', '/v2/orders', query: ['im_number' => $cdekOrder->external_order_number], cdekOrderId: $cdekOrder->id);
        if (! $result['successful']) return;
        $order = $result['data']['entity'] ?? $result['data'];
        if (! is_array($order) || empty($order['uuid'])) return;

        $latest = collect($order['statuses'] ?? [])->reject(fn (array $status) => $status['deleted'] ?? false)->sortByDesc('date_time')->first() ?? [];
        $statusCode = (string) ($latest['code'] ?? 'CREATED');
        $previousStatus = $cdekOrder->status_code;
        $tracking = ! empty($order['cdek_number']) ? 'https://www.cdek.ru/ru/tracking?order_id='.$order['cdek_number'] : null;
        $cdekOrder->update([
            'cdek_uuid' => $order['uuid'], 'cdek_number' => $order['cdek_number'] ?? null,
            'creation_state' => 'SUCCESSFUL', 'status_code' => $statusCode,
            'status_name' => $latest['name'] ?? null, 'internal_status' => $this->internalStatus($statusCode),
            'tracking_url' => $tracking, 'last_synced_at' => now(), 'last_error' => null,
        ]);
        if ($statusCode !== '') {
            $statusAt = filled($latest['date_time'] ?? null)
                ? CarbonImmutable::parse($latest['date_time'])->format('Y-m-d H:i:s')
                : null;
            CdekStatusEvent::firstOrCreate([
                'cdek_order_id' => $cdekOrder->id,
                'status_code' => $statusCode,
                'status_at' => $statusAt,
            ], [
                'source' => 'polling', 'status_name' => $latest['name'] ?? null, 'payload' => $order,
            ]);
        }
        $this->upsertShipment($cdekOrder->fresh(), $order);
        if ($previousStatus !== $statusCode) {
            try {
                $this->notificationService->notify($cdekOrder->fresh('order'), $statusCode);
            } catch (\Throwable $exception) {
                Log::error('Failed to queue CDEK delivery customer notification', [
                    'order_id' => $cdekOrder->order_id,
                    'cdek_order_id' => $cdekOrder->id,
                    'status_code' => $statusCode,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function calculateRate(Order $order): Collection { return collect(); }
    public function createShipment(Order $order): Shipment { throw new \RuntimeException('Оформление СДЭК выполняется после подтверждения оплаты.'); }
    public function getTrackingInfo(string $trackingNumber): array { return $this->client->request('GET', '/v2/orders', query: ['cdek_number' => $trackingNumber])['data']; }
    public function cancel(CdekOrder $cdekOrder): array
    {
        if (! $cdekOrder->cdek_uuid) throw new InvalidArgumentException('Заявка СДЭК ещё не создана.');
        return $this->client->request('DELETE', '/v2/orders/'.$cdekOrder->cdek_uuid, cdekOrderId: $cdekOrder->id);
    }
    public function webhooks(): array
    {
        $result = $this->client->request('GET', '/v2/webhooks');
        if (! $result['successful']) return $result;
        $result['data'] = array_is_list($result['data']) ? $result['data'] : ($result['data']['webhooks'] ?? []);
        return $result;
    }
    public function registerWebhook(string $url): array { return $this->client->request('POST', '/v2/webhooks', ['type' => 'ORDER_STATUS', 'url' => $url]); }
    public function deleteWebhook(string $uuid): array { return $this->client->request('DELETE', '/v2/webhooks/'.$uuid); }
    public function cancelShipment(Shipment $shipment): bool { return false; }
    public function printLabel(Shipment $shipment): string { return ''; }

    // Legacy admin endpoints keep their response shape while using API v2.
    public function get_offices(string $countryCode = 'ru', ?int $cityCode = null, ?int $regionCode = null, ?string $cityName = null, bool $searchRegions = true, bool $locationsOnly = false): array
    {
        $city = $cityCode ? ['code' => $cityCode] : (filled($cityName) ? collect($this->cities($cityName, $countryCode))->first() : null);
        return collect($this->pickupPoints(['country_code' => $countryCode, 'city_code' => $city['code'] ?? null]))->map(fn (array $point) => [
            'code' => $point['code'] ?? null, 'name' => $point['name'] ?? null, 'type' => $point['type'] ?? null,
            'address' => data_get($point, 'location.address'), 'full_address' => data_get($point, 'location.address_full'),
            'city' => data_get($point, 'location.city'), 'postal_code' => data_get($point, 'location.postal_code'),
            'region' => data_get($point, 'location.region'), 'longitude' => data_get($point, 'location.longitude'),
            'latitude' => data_get($point, 'location.latitude'), 'work_time' => $point['work_time'] ?? null,
            'is_dressing_room' => $point['is_dressing_room'] ?? false, 'city_code' => $city['code'] ?? null,
        ])->all();
    }
    public function location_cities(Request $request): array { return $this->cities((string) $request->input('city', ''), (string) $request->input('country_code', 'RU')); }
    public function location_regions(Request $request): array { return []; }

    private function assertSenderConfigured(): void
    {
        $sender = $this->settings['sender'] ?? [];
        if (! filled($sender['city_code'] ?? null) || ! filled($sender['address'] ?? null)) {
            throw new InvalidArgumentException('Заполните CDEK_DELIVERY_SENDER_CITY_CODE и CDEK_DELIVERY_SENDER_ADDRESS.');
        }
    }
    private function senderLocation(): array { $sender = $this->settings['sender']; return array_filter(['code' => (int) $sender['city_code'], 'postal_code' => $sender['postal_code'] ?? null, 'address' => $sender['address']]); }
    private function package(array $items, string $number): array
    {
        $items = array_values($items);
        $measurements = array_map(fn (array $item) => $this->measurement($item), $items);
        $weight = max(1, (int) collect($measurements)->sum(fn (array $item) => $item['weight'] * $item['quantity']));
        $length = max(1, (int) collect($measurements)->max('length'));
        $width = max(1, (int) collect($measurements)->max('width'));
        $height = max(1, (int) collect($measurements)->sum(fn (array $item) => $item['height'] * $item['quantity']));

        return ['number' => Str::limit($number, 30, ''), 'weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height, 'items' => array_map(fn (array $item, int $index) => ['name' => Str::limit((string) ($item['name'] ?? 'Товар'), 255, ''), 'ware_key' => (string) ($item['sku'] ?? $item['id'] ?? 'item'), 'payment' => ['value' => (float) ($item['price'] ?? 0)], 'cost' => (float) ($item['price'] ?? 0), 'amount' => $measurements[$index]['quantity'], 'weight' => $measurements[$index]['weight']], $items, array_keys($items))];
    }
    private function measurement(array $item): array
    {
        return [
            'weight' => max(1, (int) (($item['weight'] ?? null) ?: 500)),
            'length' => max(1, (int) (($item['length'] ?? null) ?: 20)),
            'width' => max(1, (int) (($item['width'] ?? null) ?: 10)),
            'height' => max(1, (int) (($item['height'] ?? null) ?: 10)),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ];
    }
    private function orderItems(Order $order): array { return $order->loadMissing('items.product', 'items.variant')->items->map(fn ($item) => ['id' => $item->id, 'name' => $item->product?->name ?? $item->legacy_name ?? 'Товар', 'sku' => $item->variant?->sku ?? $item->product?->sku ?? $item->id, 'price' => (float) $item->price, 'quantity' => (int) $item->quantity, 'weight' => $item->variant?->weight ?: $item->product?->weight, 'length' => $item->variant?->length ?: $item->product?->length, 'width' => $item->variant?->width ?: $item->product?->width, 'height' => $item->variant?->height ?: $item->product?->height])->all(); }
    private function internalStatus(string $status): string { return match ($status) { 'DELIVERED' => ShipmentStatus::DELIVERED, 'NOT_DELIVERED', 'RETURNED_TO_SENDER' => ShipmentStatus::RETURNED, 'ACCEPTED', 'CREATED' => ShipmentStatus::NEW, default => ShipmentStatus::IN_TRANSIT }; }
    private function upsertShipment(CdekOrder $cdekOrder, array $payload): void
    {
        $shipment = Shipment::updateOrCreate(['order_id' => $cdekOrder->order_id], ['delivery_method_id' => $cdekOrder->order->delivery_method_id, 'status_id' => ShipmentStatus::query()->where('code', $cdekOrder->internal_status)->value('id'), 'tracking_number' => $cdekOrder->cdek_number, 'provider_data' => $payload, 'cost' => $cdekOrder->price ?? 0]);
        $cdekOrder->update(['shipment_id' => $shipment->id]);
        $delivery = $cdekOrder->order->delivery_data ?? [];
        $cdekOrder->order->update(['tracking_number' => $cdekOrder->cdek_number, 'delivery_data' => array_merge($delivery, ['cdek_number' => $cdekOrder->cdek_number, 'tracking_url' => $cdekOrder->tracking_url])]);
    }
}
