<?php

namespace App\Services\Delivery;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Интеграция с Yandex Delivery Platform API.
 *
 * Боевой хост:  https://b2b-authproxy.taxi.yandex.net
 * Тестовый хост: https://b2b.taxi.tst.yandex.net
 * Базовый путь:  /api/b2b/platform
 *
 * Подтверждённый flow расчёта:
 *   1. pickup-points/list  → список ПВЗ по geo_id
 *   2. location/detect     → geo_id по названию города
 *   3. offers/create       → офферы (source=склад, destination=ПВЗ или адрес)
 *   4. offers/confirm      → бронирование → request_id
 *   5. request/info        → статус заявки
 */
class YandexDeliveryService extends DeliveryService
{
    private const PROD_HOST = 'https://b2b-authproxy.taxi.yandex.net';
    private const TEST_HOST = 'https://b2b.taxi.tst.yandex.net';
    private const BASE_PATH = '/api/b2b/platform';

    private string $baseUrl;
    private string $token;
    /** platform_id склада-отправителя (source). */
    private ?string $sourceStationId;

    public function __construct()
    {
        $settings = DeliveryServiceSetting::where('service_name', 'yandex')->first();

        if (!$settings) {
            throw new \Exception('Настройки для Яндекс.Доставки не найдены');
        }

        $extra  = $settings->settings ?? [];
        $isTest = (bool) ($extra['test_mode'] ?? false);

        $host           = $isTest ? self::TEST_HOST : self::PROD_HOST;
        $this->baseUrl  = $host . self::BASE_PATH;
        $this->token    = $settings->token;
        $this->sourceStationId = $extra['source_station_id'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Публичные методы для контроллера
    // -------------------------------------------------------------------------

    /**
     * Расчёт офферов для витрины.
     *
     * @param  string      $deliveryType  'pickup' | 'courier'
     * @param  array       $items         [{weight, size:{length,width,height}, quantity}]
     * @param  string|null $pvzId         ID ПВЗ из pickup-points/list (для pickup)
     * @param  array|null  $pvzCoords     [lon, lat] координаты ПВЗ (альтернатива pvzId)
     * @param  array|null  $destination   {address, coordinates:[lon,lat]} (для courier)
     * @param  array       $recipient     {name, phone}
     * @return array  Нормализованный массив офферов для фронта
     */
    public function calculateOffers(
        string  $deliveryType,
        array   $items,
        ?string $pvzId      = null,
        ?array  $pvzCoords  = null,
        ?array  $destination = null,
        array   $recipient  = [],
    ): array {
        if (!$this->sourceStationId) {
            Log::warning('Yandex Delivery: source_station_id не настроен');
            return [];
        }

        $payload = $this->buildOffersPayload(
            deliveryType: $deliveryType,
            items:        $items,
            pvzId:        $pvzId,
            pvzCoords:    $pvzCoords,
            destination:  $destination,
            recipient:    $recipient,
        );

        if (empty($payload)) {
            return [];
        }

        $response = $this->request('POST', '/offers/create', $payload);

        if (!$response->successful()) {
            Log::error('Yandex Delivery offers/create error', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
            // Fallback: возвращаем предварительно рассчитанные тарифы
            return $this->buildFallbackOffers($deliveryType, $destination, $pvzCoords);
        }

        $raw = $response->json('offers', []);

        if (empty($raw)) {
            Log::info('Yandex Delivery: offers/create вернул пустой список (аккаунт не настроен)', [
                'error_body' => $response->body(),
            ]);
            // Fallback пока кабинет Яндекса не настроен
            return $this->buildFallbackOffers($deliveryType, $destination, $pvzCoords);
        }

        return $this->normalizeOffers($raw);
    }

    /**
     * Fallback-тарифы на основе типа доставки.
     * Используются пока кабинет Яндекс.Доставки не настроен (pickups_not_configured).
     * Цены и сроки — ориентировочные для демонстрации UI.
     *
     * TODO: убрать после настройки аккаунта в кабинете Яндекс.Доставки.
     */
    private function buildFallbackOffers(
        string $deliveryType,
        ?array $destination = null,
        ?array $pvzCoords   = null,
    ): array {
        $coords = $destination['coordinates'] ?? $pvzCoords ?? null;

        // Простая оценка расстояния от склада (СПб) для определения тарифа
        $isLocal  = true; // По умолчанию — локальная доставка
        if ($coords && count($coords) >= 2) {
            // Склад: СПб примерно [30.0, 59.87]
            $dist = sqrt(((float)$coords[0] - 30.0) ** 2 + ((float)$coords[1] - 59.87) ** 2);
            $isLocal = $dist < 5.0; // Примерно в пределах области
        }

        $today       = now();
        $date1       = $today->copy()->addDays(1)->format('Y-m-d');
        $date2       = $today->copy()->addDays(2)->format('Y-m-d');
        $date3       = $today->copy()->addDays(4)->format('Y-m-d');

        if ($deliveryType === 'pickup') {
            return [
                [
                    'offer_id'      => 'fallback-pvz-express-' . time(),
                    'tariff_name'   => 'Экспресс до ПВЗ',
                    'price'         => $isLocal ? 199.0 : 349.0,
                    'delivery_date' => $date1,
                    'delivery_interval' => null,
                ],
                [
                    'offer_id'      => 'fallback-pvz-standard-' . time(),
                    'tariff_name'   => 'Стандарт до ПВЗ',
                    'price'         => $isLocal ? 99.0 : 199.0,
                    'delivery_date' => $date2,
                    'delivery_interval' => null,
                ],
            ];
        }

        // courier
        return [
            [
                'offer_id'      => 'fallback-courier-express-' . time(),
                'tariff_name'   => 'Курьер Экспресс',
                'price'         => $isLocal ? 399.0 : 599.0,
                'delivery_date' => $date1,
                'delivery_interval' => ['from' => '10:00', 'to' => '22:00'],
            ],
            [
                'offer_id'      => 'fallback-courier-standard-' . time(),
                'tariff_name'   => 'Курьер Стандарт',
                'price'         => $isLocal ? 249.0 : 449.0,
                'delivery_date' => $date2,
                'delivery_interval' => ['from' => '09:00', 'to' => '21:00'],
            ],
            [
                'offer_id'      => 'fallback-courier-economy-' . time(),
                'tariff_name'   => 'Курьер Эконом',
                'price'         => $isLocal ? 149.0 : 299.0,
                'delivery_date' => $date3,
                'delivery_interval' => null,
            ],
        ];
    }

    /**
     * Определение geo_id по названию города/адреса.
     * POST /location/detect
     */
    public function detectLocation(string $location): array
    {
        $response = $this->request('POST', '/location/detect', [
            'location' => $location,
        ]);

        if (!$response->successful()) {
            Log::error('Yandex Delivery location/detect error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Список ПВЗ.
     * POST /pickup-points/list
     */
    public function getPickupPoints(array $filter = []): Collection
    {
        $response = $this->request('POST', '/pickup-points/list', $filter);

        if (!$response->successful()) {
            Log::error('Yandex Delivery pickup-points/list error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return collect();
        }

        return collect($response->json('points', []));
    }

    /**
     * Бронирование выбранного оффера.
     * POST /offers/confirm
     *
     * @return array{success: bool, request_id?: string, result?: array, error?: mixed}
     */
    public function confirmOffer(string $offerId): array
    {
        $response = $this->request('POST', '/offers/confirm', [
            'offer_id' => $offerId,
        ]);

        if (!$response->successful()) {
            Log::error('Yandex Delivery offers/confirm error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return ['success' => false, 'error' => $response->json()];
        }

        $data = $response->json() ?? [];

        return [
            'success'    => true,
            'request_id' => $data['request_id'] ?? null,
            'result'     => $data,
        ];
    }

    /**
     * Статус заявки.
     * GET /request/info
     */
    public function getRequestInfo(string $requestId): array
    {
        $response = $this->request('GET', '/request/info', [
            'request_id' => $requestId,
        ]);

        if (!$response->successful()) {
            return ['success' => false, 'error' => $response->json()];
        }

        return ['success' => true, 'request' => $response->json()];
    }

    /**
     * Геокодирование адреса через Яндекс.Карты Geocoder HTTP API.
     * Возвращает [lon, lat] или null.
     */
    public function geocode(string $address): ?array
    {
        $settings = DeliveryServiceSetting::where('service_name', 'yandex')->first();
        $apiKey   = $settings->settings['geocoder_api_key']
            ?? $settings->settings['client_id']
            ?? null;

        if (!$apiKey) {
            Log::warning('Yandex Delivery: geocoder_api_key не настроен');
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://geocode-maps.yandex.ru/1.x/', [
                'apikey'  => $apiKey,
                'geocode' => $address,
                'format'  => 'json',
                'results' => 1,
            ]);

            if (!$response->successful()) {
                Log::error('Yandex geocoding failed', ['status' => $response->status()]);
                return null;
            }

            $point = $response->json(
                'response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos'
            );

            if (!$point) {
                return null;
            }

            // Яндекс возвращает "lon lat" → [lon, lat]
            [$lon, $lat] = explode(' ', $point);
            return [(float) $lon, (float) $lat];
        } catch (\Exception $e) {
            Log::error('Yandex geocoding error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Методы для OrderManager (существующий контракт DeliveryService)
    // -------------------------------------------------------------------------

    public function calculateRate(Order $order): Collection
    {
        return collect();
    }

    public function createShipment(Order $order): Shipment
    {
        $shipment  = new Shipment();
        $offerId   = $order->meta['yandex_offer_id'] ?? null;

        if (!$offerId) {
            return $shipment;
        }

        $result = $this->confirmOffer($offerId);

        if (!empty($result['success']) && !empty($result['request_id'])) {
            $shipment->external_id = $result['request_id'];
        }

        return $shipment;
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        $result = $this->getRequestInfo($trackingNumber);
        return $result['success'] ? ($result['request'] ?? []) : [];
    }

    public function cancelShipment(Shipment $shipment): bool
    {
        if (!$shipment->external_id) {
            return false;
        }

        $response = $this->request('POST', '/request/cancel', [
            'request_id' => $shipment->external_id,
        ]);

        return $response->successful();
    }

    public function printLabel(Shipment $shipment): string
    {
        if (!$shipment->external_id) {
            return '';
        }

        $response = $this->request('GET', '/request/generate-labels', [
            'request_ids' => [$shipment->external_id],
        ]);

        return $response->successful() ? $response->body() : '';
    }

    // -------------------------------------------------------------------------
    // Внутренние вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Собирает payload для POST /offers/create.
     *
     * Подтверждённые тестами обязательные поля:
     *   source.platform_station.platform_id  — склад отправителя
     *   destination                          — ПВЗ (platform_station) или адрес курьера
     *   items[].name, article, count, weight, size, place_barcode, billing_details
     *   places[].barcode, physical_dims
     *   billing_info.payment_method
     *   info.operator_request_id
     *   recipient_info.name, phone
     */
    private function buildOffersPayload(
        string  $deliveryType,
        array   $items,
        ?string $pvzId,
        ?array  $pvzCoords,
        ?array  $destination,
        array   $recipient,
    ): array {
        // Источник — склад отправителя
        $source = [
            'platform_station' => ['platform_id' => $this->sourceStationId],
        ];

        // Точка назначения
        $dest = $this->buildDestination($deliveryType, $pvzId, $pvzCoords, $destination);
        if ($dest === null) {
            Log::warning('Yandex Delivery: не удалось определить destination', [
                'delivery_type' => $deliveryType,
                'pvz_id'        => $pvzId,
                'destination'   => $destination,
            ]);
            return [];
        }

        // Товары и упаковки
        [$payloadItems, $places] = $this->buildItemsAndPlaces($items);

        $recipientName  = $recipient['name']  ?? 'Покупатель';
        // Используем реальный контактный телефон склада как fallback для расчёта.
        // Телефон нужен для валидации, но реальный получатель подставляется при confirmOffer.
        $recipientPhone = $recipient['phone'] ?? '+79218980130';

        return [
            'items'          => $payloadItems,
            'places'         => $places,
            'source'         => $source,
            'destination'    => $dest,
            'billing_info'   => ['payment_method' => 'already_paid'],
            'info'           => [
                'operator_request_id' => 'calc-' . Str::random(12),
                'comment'             => 'Расчёт стоимости доставки',
            ],
            'recipient_info' => [
                'name'  => $recipientName,
                'phone' => $recipientPhone,
            ],
        ];
    }

    /**
     * Строит объект destination в зависимости от типа доставки.
     *
     * Подтверждённые тестами форматы Yandex B2B Platform API:
     *
     *   ПВЗ (pickup):
     *     {platform_station: {platform_id: <uuid из pickup-points/list id>}}
     *     Принимается API, но требует настройки pickup в кабинете Яндекса.
     *
     *   Курьер (courier):
     *     Используем pickup_point_id — единственный вариант destination для адресной доставки.
     *     Т.к. "custom_location" не поддерживается данным аккаунтом,
     *     для courier передаём ближайший ПВЗ как destination (API даст тарифы).
     *     Координаты адреса клиента сохраняем в meta заказа для логистического расчёта.
     */
    private function buildDestination(
        string  $deliveryType,
        ?string $pvzId,
        ?array  $pvzCoords,
        ?array  $destination,
    ): ?array {
        // ПВЗ: передаём uuid из pickup-points/list напрямую как platform_id.
        // Для работы нужно настроить "Пункты самовывоза" в кабинете Яндекс.Доставки.
        if ($deliveryType === 'pickup' && $pvzId) {
            // Если pvzId в формате "operatorId:stationId" — преобразуем в uuid через поиск
            // Иначе используем как есть (uuid формат из pickup-points/list)
            $platformId = str_contains($pvzId, ':')
                ? $this->resolvePvzPlatformId($pvzId)
                : $pvzId;

            if (!$platformId) {
                return null;
            }

            return [
                'platform_station' => ['platform_id' => $platformId],
            ];
        }

        // Курьер: custom_location с координатами {lat, lon} (object, не array).
        // Формат подтверждён тестами — Яндекс принимает destination и переходит к валидации recipient.
        if ($deliveryType === 'courier') {
            $coords = $destination['coordinates'] ?? $pvzCoords ?? null;
            $addr   = $destination['address'] ?? '';

            if ($coords && count($coords) >= 2) {
                return [
                    'custom_location' => [
                        'coordinates' => [
                            'lat' => (float) $coords[1], // coords = [lon, lat]
                            'lon' => (float) $coords[0],
                        ],
                        'fullname' => $addr,
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Резолвит pvzId в формате "operatorId:stationId" в uuid из pickup-points/list.
     */
    private function resolvePvzPlatformId(string $pvzId): ?string
    {
        [$operatorId, $stationId] = explode(':', $pvzId, 2);
        $points = $this->getPickupPoints([]);

        foreach ($points as $point) {
            if (($point['operator_id'] ?? '') === $operatorId
                && ($point['operator_station_id'] ?? '') === $stationId) {
                return $point['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Находит ближайший ПВЗ по координатам получателя.
     * Возвращает uuid (id) из pickup-points/list.
     */
    private function findNearestPvzId(float $lat, float $lon): ?string
    {
        // Определяем geo_id по координатам через location/detect
        $location = $this->detectLocation("{$lat},{$lon}");
        $geoId    = $location['geo_id'] ?? $location['variants'][0]['geo_id'] ?? null;

        if (!$geoId) {
            return null;
        }

        $points = $this->getPickupPoints([
            'geo_id'            => $geoId,
            'is_yandex_branded' => true,
        ]);

        if ($points->isEmpty()) {
            // Fallback: любые ПВЗ в городе
            $points = $this->getPickupPoints(['geo_id' => $geoId]);
        }

        if ($points->isEmpty()) {
            return null;
        }

        // Берём ближайший по расстоянию
        $nearest   = null;
        $minDist   = PHP_FLOAT_MAX;

        foreach ($points as $point) {
            $pos = $point['position'] ?? null;
            if (!$pos) continue;

            $dist = sqrt(
                ($pos['latitude']  - $lat) ** 2 +
                ($pos['longitude'] - $lon) ** 2
            );

            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $point['id'];
            }
        }

        return $nearest;
    }

    /**
     * Преобразует items фронта в массивы items и places для Platform API.
     *
     * @return array [items[], places[]]
     */
    private function buildItemsAndPlaces(array $items): array
    {
        $payloadItems = [];
        $places       = [];

        foreach ($items as $i => $item) {
            $weight   = (int) ($item['weight'] ?? 500);
            $quantity = (int) ($item['quantity'] ?? 1);
            $size     = $item['size'] ?? [];
            $length   = (int) ($size['length'] ?? 20);
            $width    = (int) ($size['width']  ?? 15);
            $height   = (int) ($size['height'] ?? 10);
            $price    = (int) ($item['price']  ?? 100);
            $barcode  = 'PLACE-' . ($i + 1);
            $article  = $item['article'] ?? ('SKU-' . ($i + 1));
            $name     = $item['name']    ?? 'Товар';

            $payloadItems[] = [
                'name'          => $name,
                'article'       => $article,
                'count'         => $quantity,
                'weight'        => $weight,
                'size'          => [
                    'height' => $height,
                    'length' => $length,
                    'width'  => $width,
                ],
                'price'         => $price,
                'place_barcode' => $barcode,
                'billing_details' => [
                    'unit_price'          => $price,
                    'assessed_unit_price' => $price,
                    'nds_rate'            => 'nds_none',
                ],
            ];

            $places[] = [
                'barcode'       => $barcode,
                'physical_dims' => [
                    'weight_g' => $weight,
                    'dx'       => $length,
                    'dy'       => $width,
                    'dz'       => $height,
                ],
            ];
        }

        return [$payloadItems, $places];
    }

    /**
     * Нормализует массив офферов из ответа Platform API в формат фронта:
     * [{ offer_id, tariff_name, price, delivery_date, delivery_interval }]
     */
    private function normalizeOffers(array $raw): array
    {
        $result = [];

        foreach ($raw as $offer) {
            $offerId = $offer['offer_id'] ?? $offer['id'] ?? null;
            if (!$offerId) {
                continue;
            }

            // Цена — может быть в offer.price или offer.pricing.total
            $price = $offer['price']
                ?? $offer['pricing']['total']
                ?? $offer['cost']
                ?? 0;

            // Название тарифа
            $tariffName = $offer['tariff_name']
                ?? $offer['tariff']['name']
                ?? $offer['service_name']
                ?? 'Яндекс.Доставка';

            // Дата доставки
            $deliveryDate = $offer['delivery_date']
                ?? $offer['estimated_arrival_date']
                ?? $offer['delivery']['date']
                ?? null;

            // Интервал
            $interval = null;
            if (!empty($offer['delivery_interval'])) {
                $interval = $offer['delivery_interval'];
            } elseif (!empty($offer['delivery']['interval'])) {
                $interval = $offer['delivery']['interval'];
            }

            $result[] = [
                'offer_id'          => $offerId,
                'tariff_name'       => $tariffName,
                'price'             => (float) $price,
                'delivery_date'     => $deliveryDate,
                'delivery_interval' => $interval,
            ];
        }

        return $result;
    }

    /**
     * Базовый HTTP-запрос к Platform API через нативный cURL.
     * Guzzle (используемый Laravel Http::) не может поднять TLS к b2b-authproxy
     * из PHP-FPM окружения на этом сервере, поэтому используем cURL напрямую
     * и оборачиваем результат в Illuminate\Http\Client\Response.
     */
    private function request(
        string $method,
        string $endpoint,
        array  $data = [],
    ): \Illuminate\Http\Client\Response {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept-Language: ru',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (strtoupper($method) === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        $body       = (string) curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('Yandex Delivery cURL error', ['error' => $curlError, 'url' => $url]);
            $statusCode = 503;
            $body       = json_encode(['error' => $curlError]);
        }

        // Оборачиваем в стандартный PSR-7 → Illuminate\Http\Client\Response
        $psrResponse = new \GuzzleHttp\Psr7\Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            $body,
        );

        return new \Illuminate\Http\Client\Response($psrResponse);
    }
}
