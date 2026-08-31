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
use App\Services\Order\OrderCreationService;
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
        $database = $this->withoutEmptyOverrides(
            DeliveryServiceSetting::query()->where('service_name', 'cdek')->value('settings') ?? [],
        );
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
        $points = $result['successful'] ? ($result['data'] ?? []) : [];
        // For COD only points that can accept cashless payment are meaningful.
        // If CDEK does not return this capability, keep the point visible so a
        // provider-side change cannot empty checkout completely.
        if (! empty($this->settings['cod_pickup_only'])) {
            $points = array_values(array_filter($points, fn (array $point) => ! array_key_exists('have_cashless', $point) || (bool) $point['have_cashless']));
        }
        return $points;
    }

    /** Tariffs available under the connected CDEK contract for the admin setup form. */
    public function availableTariffs(): array
    {
        $result = $this->client->request('GET', '/v2/calculator/alltariffs');
        if (! $result['successful']) return [];

        $tariffs = $result['data']['tariff_codes'] ?? $result['data'] ?? [];

        return collect($tariffs)
            ->flatMap(function (array $tariff) {
                // Current CDEK API response groups codes by tariff name and
                // puts individual variants in delivery_modes. Keep support
                // for the earlier flat shape too.
                $modes = isset($tariff['tariff_code']) ? [$tariff] : ($tariff['delivery_modes'] ?? []);

                return collect($modes)->map(fn (array $mode) => [
                    'code' => (int) ($mode['tariff_code'] ?? 0),
                    'name' => trim(implode(' ', array_filter([
                        $tariff['tariff_name'] ?? null,
                        $mode['delivery_mode_name'] ?? $tariff['delivery_mode_name'] ?? null,
                    ]))),
                ]);
            })
            ->filter(fn (array $tariff) => $tariff['code'] > 0 && $tariff['name'] !== '')
            ->sortBy('name')->values()->all();
    }

    public function calculateTariffs(string $deliveryType, array $destination, array $items, ?string $pickupPoint = null): array
    {
        $this->assertSenderConfigured();
        $isPickup = in_array($deliveryType, ['pickup', 'postamat'], true);
        if ($isPickup && ! $pickupPoint) throw new InvalidArgumentException($deliveryType === 'postamat' ? 'Выберите постамат СДЭК.' : 'Выберите пункт выдачи СДЭК.');
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
        if ($isPickup) $payload['delivery_point'] = $pickupPoint;

        $result = $this->client->request('POST', '/v2/calculator/tarifflist', $payload);
        if (! $result['successful']) return [];

        // CDEK calls modes 1/2 "from door" and 3/4 "from warehouse".
        // The selected tariffs are frequently the warehouse variants (136,
        // 137 and 368), so filtering only by 1/2 made valid tariffs vanish
        // from checkout.
        $deliveryModes = $this->deliveryModes($deliveryType);
        $allowedCodes = array_map('intval', $this->settings['tariff_codes'] ?? []);
        $daysOffset = max(0, (int) ($this->settings['delivery_days_offset'] ?? 0));
        return collect($result['data']['tariff_codes'] ?? [])
            ->filter(fn (array $tariff) => in_array((int) ($tariff['delivery_mode'] ?? 0), $deliveryModes, true))
            ->filter(fn (array $tariff) => $allowedCodes === [] || in_array((int) ($tariff['tariff_code'] ?? 0), $allowedCodes, true))
            ->map(fn (array $tariff) => $this->presentTariff($tariff, $deliveryType, $daysOffset))
            ->values()->all();
    }

    /**
     * Recalculate the client-selected option with product data from the server
     * so delivery cost and the pickup point cannot be forged at checkout.
     */
    public function revalidateCheckout(array $delivery, array $items): array
    {
        $deliveryType = (string) ($delivery['delivery_type'] ?? '');
        $destination = $delivery['destination'] ?? [];
        $pickupCode = $delivery['pvz']['code'] ?? null;
        $tariffCode = (int) ($delivery['tariff_code'] ?? 0);

        if (! in_array($deliveryType, ['courier', 'pickup', 'postamat'], true) || ! is_array($destination) || $tariffCode < 1) {
            throw new InvalidArgumentException('Выберите актуальный тариф СДЭК.');
        }

        if (in_array($deliveryType, ['pickup', 'postamat'], true)) {
            $point = collect($this->pickupPoints([
                'city_code' => $destination['city_code'] ?? null,
                'type' => 'ALL',
            ]))->firstWhere('code', $pickupCode);

            if (! $point) {
                throw new InvalidArgumentException('Выбранный пункт выдачи СДЭК недоступен.');
            }
        }

        $tariff = collect($this->calculateTariffs(
            $deliveryType,
            $destination,
            array_map(fn (array $item) => $this->checkoutItem($item), $items),
            $pickupCode,
        ))->firstWhere('tariff_code', $tariffCode);

        if (! $tariff) {
            throw new InvalidArgumentException('Выбранный тариф СДЭК больше недоступен. Выберите другой тариф.');
        }

        return array_filter([
            'provider' => 'cdek',
            'delivery_type' => $deliveryType,
            ...$tariff,
            'destination' => $destination,
            'pvz' => in_array($deliveryType, ['pickup', 'postamat'], true) ? [
                'code' => $point['code'],
                'type' => $point['type'] ?? null,
                'address' => data_get($point, 'location.address') ?? data_get($point, 'location.address_full'),
                'coordinates' => [data_get($point, 'location.longitude'), data_get($point, 'location.latitude')],
            ] : null,
        ], fn ($value) => $value !== null);
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
        $isPickup = in_array($delivery['delivery_type'] ?? null, ['pickup', 'postamat'], true);
        $payload = [
            'type' => (int) ($this->settings['order_type'] ?? 1),
            'number' => $cdekOrder->external_order_number,
            'tariff_code' => (int) $tariff,
            'recipient' => ['name' => $recipientName, 'phones' => [['number' => $recipientPhone]]],
            'from_location' => $this->senderLocation(),
            'packages' => [$this->package($this->orderItems($order), $cdekOrder->external_order_number)],
        ];
        if (filled(data_get($this->settings, 'sender.name'))) {
            $payload['sender'] = array_filter([
                'name' => data_get($this->settings, 'sender.name'),
                'phones' => filled(data_get($this->settings, 'sender.phone')) ? [['number' => data_get($this->settings, 'sender.phone')]] : null,
            ]);
        }
        $payload = array_replace($payload, $this->orderPriceModifiers($this->orderItems($order)));
        $services = $this->additionalServices();
        if ($services !== []) $payload['services'] = $services;
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
        $this->syncConfiguredOrderStatus($cdekOrder->fresh('order'), $statusCode);
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

    /** Add the admin-configured presentation without changing CDEK's source tariff name. */
    private function presentTariff(array $tariff, string $deliveryType, int $daysOffset): array
    {
        $sourceName = (string) ($tariff['tariff_name'] ?? 'Тариф СДЭК');
        $display = $this->settings['tariff_display'] ?? [];
        $custom = $display['custom_names'][(string) ($tariff['tariff_code'] ?? '')] ?? [];
        $fullName = filled($custom['name'] ?? null) ? (string) $custom['name'] : $sourceName;
        $shortName = filled($custom['short_name'] ?? null) ? (string) $custom['short_name'] : preg_replace('/^(Посылка|Экономичная посылка|Магистральный экспресс)\s+/ui', '', $fullName);
        $deliveryName = match ($deliveryType) {
            'courier' => 'СДЭК: Курьерская доставка',
            'postamat' => 'СДЭК: Постамат',
            default => 'СДЭК: Пункт выдачи',
        };
        $title = match ($display['name_source'] ?? 'delivery') {
            'full' => $fullName,
            'short' => $shortName,
            default => $deliveryName,
        };
        $description = match ($display['description_source'] ?? 'full') {
            'delivery' => $deliveryName,
            'short' => $shortName,
            'description' => $sourceName,
            default => $fullName,
        };

        return [
            'tariff_code' => $tariff['tariff_code'], 'tariff_name' => $sourceName,
            'display_name' => $title, 'display_description' => $description,
            'show_tariff_label' => (bool) ($display['show_label'] ?? true),
            'delivery_mode' => $tariff['delivery_mode'], 'price' => $this->checkoutPrice((float) $tariff['delivery_sum'], (int) $tariff['tariff_code']), 'currency' => 'RUB',
            'period' => ['min' => (int) $tariff['period_min'] + $daysOffset, 'max' => (int) $tariff['period_max'] + $daysOffset],
            'delivery_date_range' => $tariff['delivery_date_range'] ?? null,
        ];
    }

    /**
     * Settings page stores only fields changed by a manager. A legacy record
     * may contain null/empty placeholders; those must not overwrite protected
     * operational values from .env (CDEK OAuth and sender data).
     */
    private function withoutEmptyOverrides(array $settings): array
    {
        $result = [];
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $nested = $this->withoutEmptyOverrides($value);
                if ($nested !== []) $result[$key] = $nested;
            } elseif ($value !== null && $value !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function assertSenderConfigured(): void
    {
        $sender = $this->settings['sender'] ?? [];
        if (! filled($sender['city_code'] ?? null) || ! filled($sender['address'] ?? null)) {
            throw new InvalidArgumentException('Заполните CDEK_DELIVERY_SENDER_CITY_CODE и CDEK_DELIVERY_SENDER_ADDRESS.');
        }
    }
    private function senderLocation(): array { $sender = $this->settings['sender']; return array_filter(['code' => (int) $sender['city_code'], 'postal_code' => $sender['postal_code'] ?? null, 'address' => $sender['address']]); }
    private function deliveryModes(string $deliveryType): array
    {
        $modes = match ($deliveryType) {
            'courier' => [1, 3],
            'postamat' => [6, 7],
            default => [2, 4],
        };
        return match ($this->settings['tariff_mode'] ?? 'any') {
            'dver' => array_values(array_intersect($modes, [1, 2, 6])),
            'sklad' => array_values(array_intersect($modes, [3, 4, 7])),
            default => $modes,
        };
    }
    private function checkoutPrice(float $price, int $tariffCode): float
    {
        // The manager's selected tariff list is the free-delivery list:
        // selected codes are displayed in checkout with a zero cost.
        if (in_array($tariffCode, array_map('intval', $this->settings['tariff_codes'] ?? []), true)) return 0.0;
        $rules = $this->settings['price_rules'] ?? [];
        $price += max(0, (float) ($rules['add_cost'] ?? 0));
        return match ((string) ($rules['rounded'] ?? '0')) {
            '1' => (float) ceil($price),
            '2' => (float) floor($price),
            default => round($price, 2),
        };
    }
    private function additionalServices(): array
    {
        $services = $this->settings['services'] ?? [];
        // Service codes are CDEK API v2 identifiers. They are sent only for
        // a real order; the calculator determines their availability itself.
        return array_values(array_filter([
            ! empty($services['fitting']) ? ['code' => 'TRYING_ON'] : null,
            ! empty($services['partial_delivery']) ? ['code' => 'PART_DELIV'] : null,
            ! empty($services['no_inspection']) ? ['code' => 'NOT_INSPECTION'] : null,
        ]));
    }
    private function orderPriceModifiers(array $items): array
    {
        $rules = $this->settings['price_rules'] ?? [];
        $deliveryCost = max(0, (float) ($rules['add_rko'] ?? 0));
        $extraCost = max(0, (float) ($rules['add_drc'] ?? 0));
        $threshold = max(0, (float) ($rules['add_drc_adv'] ?? 0));
        $orderTotal = collect($items)->sum(fn (array $item) => (float) $item['price'] * (int) $item['quantity']);
        $vatRate = data_get($this->settings, 'delivery_vat');
        $vat = in_array((int) $vatRate, [0, 5, 7, 10, 16, 22], true) ? (int) $vatRate : null;
        $result = [];
        if ($deliveryCost > 0) $result['delivery_recipient_cost'] = array_filter(['value' => $deliveryCost, 'vat_rate' => $vat], fn ($value) => $value !== null);
        if ($extraCost > 0 && $threshold > 0 && $orderTotal <= $threshold) {
            $result['delivery_recipient_cost_adv'] = [array_filter(['threshold' => (int) $threshold, 'sum' => $extraCost, 'vat_rate' => $vat], fn ($value) => $value !== null)];
        }
        return $result;
    }
    private function package(array $items, string $number): array
    {
        $items = array_values($items);
        $measurements = array_map(fn (array $item) => $this->measurement($item), $items);
        $fallback = $this->settings['default_package'] ?? [];
        $useOrderFallback = ($this->settings['default_weight_scope'] ?? 'item') === 'order'
            && collect($items)->contains(fn (array $item) => ! filled($item['weight'] ?? null));
        $weight = $useOrderFallback ? max(1, (int) ($fallback['weight'] ?? 500)) : max(1, (int) collect($measurements)->sum(fn (array $item) => $item['weight'] * $item['quantity']));
        $length = max(1, (int) collect($measurements)->max('length'));
        $width = max(1, (int) collect($measurements)->max('width'));
        $height = max(1, (int) collect($measurements)->sum(fn (array $item) => $item['height'] * $item['quantity']));

        return ['number' => Str::limit($number, 30, ''), 'weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height, 'items' => array_map(fn (array $item, int $index) => ['name' => Str::limit((string) ($item['name'] ?? 'Товар'), 255, ''), 'ware_key' => (string) ($item['sku'] ?? $item['id'] ?? 'item'), 'payment' => ['value' => (float) ($item['price'] ?? 0)], 'cost' => $this->declaredCost((float) ($item['price'] ?? 0)), 'amount' => $measurements[$index]['quantity'], 'weight' => $measurements[$index]['weight']], $items, array_keys($items))];
    }
    private function declaredCost(float $price): float
    {
        $declared = $this->settings['declared'] ?? [];
        if ((float) ($declared['value'] ?? 0) > 0) return (float) $declared['value'];
        if ((float) ($declared['percent'] ?? 0) > 0) return round($price * (float) $declared['percent'] / 100, 2);
        return $price;
    }
    private function syncConfiguredOrderStatus(CdekOrder $cdekOrder, string $statusCode): void
    {
        if (empty($this->settings['use_import']) || ! $cdekOrder->order) return;
        $legacyCode = match ($statusCode) {
            'CREATED' => '1', 'ACCEPTED' => '3', 'DELIVERED' => '4',
            'NOT_DELIVERED' => '5', 'RETURNED_TO_SENDER' => '18',
            'IN_POSTAMAT' => '29', default => '0',
        };
        $shopStatus = collect($this->settings['status_mapping'] ?? [])
            ->filter(fn ($cdekCode) => (string) $cdekCode === $legacyCode)
            ->keys()->first();
        $target = match ($shopStatus) {
            'new' => \App\Enums\OrderStatus::NEW,
            'processing', 'approved', 'return_process' => \App\Enums\OrderStatus::PROCESSING,
            'shipped', 'exported' => \App\Enums\OrderStatus::SHIPPED,
            'delivered' => \App\Enums\OrderStatus::DELIVERED,
            'cancelled' => \App\Enums\OrderStatus::CANCELLED,
            'returned' => \App\Enums\OrderStatus::PRODUCT_RETURN,
            default => null,
        };
        if ($target && $cdekOrder->order->status !== $target) app(OrderCreationService::class)->updateOrderStatus($cdekOrder->order, $target);
    }
    private function measurement(array $item): array
    {
        $fallback = $this->settings['default_package'] ?? [];
        return [
            'weight' => max(1, (int) (($item['weight'] ?? null) ?: ($fallback['weight'] ?? 500))),
            'length' => max(1, (int) (($item['length'] ?? null) ?: ($fallback['length'] ?? 20))),
            'width' => max(1, (int) (($item['width'] ?? null) ?: ($fallback['width'] ?? 10))),
            'height' => max(1, (int) (($item['height'] ?? null) ?: ($fallback['height'] ?? 10))),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ];
    }
    private function checkoutItem(array $item): array
    {
        $model = $item['model'] ?? null;
        $product = $model?->product;

        return [
            'id' => $model?->id,
            'name' => $item['name'] ?? $model?->name ?? $product?->name ?? 'Товар',
            'sku' => $model?->sku ?? $product?->sku,
            'price' => (float) ($item['final_price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'weight' => $model?->weight ?: $product?->weight,
            'length' => $model?->length ?: $product?->length,
            'width' => $model?->width ?: $product?->width,
            'height' => $model?->height ?: $product?->height,
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
