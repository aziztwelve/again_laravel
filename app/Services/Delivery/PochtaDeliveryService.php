<?php

namespace App\Services\Delivery;

use App\Models\Country;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\Cdek\CdekParcel;
use App\Services\Delivery\Pochta\PochtaClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Почта России: расчёт доставки и поиск отделений для чекаута.
 *
 * Способы доставки (коды из delivery_methods): russian_post_office, russian_post_courier,
 * international_pickup, international_courier. Виды отправлений каждого способа
 * лежат в config/services.php → pochta.methods.
 *
 * Оформление отправлений, печать форм и отслеживание — отдельные этапы, здесь их нет.
 */
class PochtaDeliveryService extends DeliveryService
{
    /** Коды способов доставки (delivery_methods.code), которые обслуживает этот сервис. */
    public const METHOD_CODES = ['russian_post_office', 'russian_post_courier', 'international_pickup', 'international_courier'];

    /**
     * Куда можно адресовать посылку и получить тариф. Почтоматы и ППВЗ тарифный
     * API аккаунта оценивает нулём (проверено 2026-11-25), поэтому в выбор не попадают.
     */
    private const OFFICE_TYPES = ['ГОПС', 'СОПС'];

    private const ADDRESS_QUALITY_OK = ['GOOD', 'POSTAL_BOX', 'ON_DEMAND'];

    private const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    private PochtaClient $client;

    public function __construct(array $methodSettings = [])
    {
        $database = $this->withoutEmptyOverrides(
            DeliveryServiceSetting::query()->where('service_name', 'pochta')->value('settings') ?? [],
        );
        $this->settings = array_replace_recursive(config('services.pochta'), $database, $methodSettings);
        $this->client = new PochtaClient($this->settings);
    }

    /**
     * Доступны ли способы Почты России покупателю. Пока интеграция не открыта всем
     * (services.pochta.public), их видят только тестировщики из services.pochta.testers.
     */
    public static function visibleTo(?object $user): bool
    {
        if (config('services.pochta.public')) {
            return true;
        }

        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        return $email !== '' && in_array($email, array_map('mb_strtolower', (array) config('services.pochta.testers', [])), true);
    }

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    public function supports(string $methodCode): bool
    {
        return isset($this->settings['methods'][$methodCode]);
    }

    /** Способ доставки международный (для него не нужен индекс, нужна страна). */
    public function isInternational(string $methodCode): bool
    {
        return (bool) ($this->settings['methods'][$methodCode]['international'] ?? false);
    }

    /**
     * Тарифы способа доставки для адреса и корзины.
     *
     * $destination: index (индекс выбранного отделения или адреса), city, address, country_code (ISO-2).
     * $items: name, weight, length, width, height, price, quantity.
     *
     * @return array<int, array{tariff_code:string,tariff_name:string,display_name:string,mail_type:string,price:float,currency:string,period:array{min:int,max:int}}>
     *
     * @throws InvalidArgumentException причина в данных покупателя (адрес, страна, нет тарифов)
     * @throws RuntimeException API Почты недоступен или не настроен
     */
    public function calculateTariffs(string $methodCode, array $destination, array $items): array
    {
        $method = $this->settings['methods'][$methodCode] ?? null;
        if (! $method) {
            throw new InvalidArgumentException('Способ доставки не поддерживается Почтой России.');
        }
        if (! $this->enabled()) {
            throw new RuntimeException('Интеграция Почты России не настроена.');
        }

        $international = (bool) ($method['international'] ?? false);
        $country = strtoupper((string) ($destination['country_code'] ?? 'RU'));
        if (! $international && $country !== 'RU') {
            throw new InvalidArgumentException('Этот способ доставки Почтой России работает только по России.');
        }
        if ($international && $country === 'RU') {
            throw new InvalidArgumentException('Международная доставка Почтой России недоступна для России.');
        }

        $target = $international
            ? ['mail-direct' => $this->countryCode($country)]
            : ['index-to' => $this->domesticIndex($method, $destination)];

        $parcel = $this->parcel($items);
        $tariffs = [];
        $lastError = null;

        foreach ($method['products'] as $product) {
            if (isset($product['max_mass']) && $parcel['mass'] > $product['max_mass']) {
                continue;
            }

            $payload = $this->tariffPayload($product, $parcel, $target, $international);
            $row = $this->tariff($product, $payload);
            if ($row === null) {
                continue;
            }
            if (isset($row['error'])) {
                $lastError = $row['error'];
                continue;
            }
            $tariffs[] = $row;
        }

        if (! $tariffs) {
            if ($lastError === 'unavailable') {
                throw new RuntimeException('Почта России сейчас недоступна. Попробуйте позже.');
            }
            throw new InvalidArgumentException('Для указанного адреса нет доступных тарифов Почты России.');
        }

        usort($tariffs, fn (array $left, array $right) => $left['price'] <=> $right['price']);

        return $tariffs;
    }

    /**
     * Адрес назначения с почтовым индексом для способов по России: индекс выбранного отделения
     * или найденный по адресу (нормализация Почты). Международные способы остаются как есть.
     *
     * @throws InvalidArgumentException отделение не выбрано или адрес не распознан
     * @throws RuntimeException API Почты недоступен
     */
    public function resolveDestination(string $methodCode, array $destination): array
    {
        $method = $this->settings['methods'][$methodCode] ?? null;
        if (! $method || ($method['international'] ?? false)) {
            return $destination;
        }
        if (! $this->enabled()) {
            throw new RuntimeException('Интеграция Почты России не настроена.');
        }

        $destination['index'] = $this->domesticIndex($method, $destination);

        return $destination;
    }

    /**
     * Проверка выбранной доставки при создании заказа: цену, срок и вид доставки берём не из запроса,
     * а из свежего расчёта, иначе покупатель мог бы подменить цену. Позиции — validated_items
     * публичного чекаута (final_price уже после скидок на позицию).
     *
     * @return array<string, mixed> delivery_data для сохранения в заказе
     *
     * @throws InvalidArgumentException выбранный тариф недоступен или адрес не подходит
     * @throws RuntimeException API Почты недоступен
     */
    public function revalidateCheckout(array $delivery, array $items, string $methodCode): array
    {
        $method = $this->settings['methods'][$methodCode] ?? null;
        $mailType = (string) ($delivery['tariff_code'] ?? '');
        $destination = is_array($delivery['destination'] ?? null) ? $delivery['destination'] : [];
        if (! $method || $mailType === '') {
            throw new InvalidArgumentException('Выберите актуальный вариант доставки Почтой России.');
        }

        // Индекс по адресу нужен не только для цены: его получит менеджер при отправке заказа.
        $destination = $this->resolveDestination($methodCode, $destination);

        $tariff = collect($this->calculateTariffs($methodCode, $destination, array_map(fn (array $item) => $this->checkoutItem($item), $items)))
            ->firstWhere('tariff_code', $mailType);
        if (! $tariff) {
            throw new InvalidArgumentException('Выбранный вариант доставки Почтой России больше недоступен. Выберите другой.');
        }

        $office = ($method['delivery_type'] ?? null) === 'office' ? (array) ($delivery['pvz'] ?? []) : null;
        if ($office !== null && preg_replace('/\D/', '', (string) ($office['code'] ?? '')) !== preg_replace('/\D/', '', (string) ($destination['index'] ?? ''))) {
            throw new InvalidArgumentException('Выберите отделение Почты России.');
        }

        return array_filter([
            'provider' => 'pochta',
            'delivery_type' => $method['delivery_type'],
            ...$tariff,
            'destination' => array_filter([
                'index' => $destination['index'] ?? null,
                'city' => $destination['city'] ?? null,
                'address' => $destination['address'] ?? null,
                'country_code' => isset($destination['country_code']) ? strtoupper((string) $destination['country_code']) : null,
            ], fn ($value) => $value !== null && $value !== ''),
            'pvz' => $office !== null ? array_filter([
                'code' => (string) $office['code'],
                'type' => $office['type'] ?? null,
                'address' => $office['address'] ?? null,
                'coordinates' => $office['coordinates'] ?? null,
            ], fn ($value) => $value !== null) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Отделения рядом с населённым пунктом, куда можно адресовать посылку.
     *
     * Координаты берутся из запроса или определяются геокодером по названию города.
     * Если геокодер не ответил, используется поиск Почты по адресу.
     *
     * @return array<int, array{code:string,type:string,address:string,latitude:float,longitude:float,distance_km:?float,hours:string}>
     */
    public function offices(string $city, ?float $latitude = null, ?float $longitude = null, int $limit = 40): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Интеграция Почты России не настроена.');
        }

        if ($latitude === null || $longitude === null) {
            $position = app(YandexDeliveryService::class)->geocode($city);
            if ($position) {
                [$longitude, $latitude] = $position;
            }
        }

        if ($latitude === null || $longitude === null) {
            return $this->officesByAddress($city, $limit);
        }

        $key = sprintf('pochta:offices:%.3f:%.3f:%d', $latitude, $longitude, $limit);
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $offices = [];
        foreach ([30, 100] as $radius) {
            $result = $this->client->request('GET', '/postoffice/1.0/nearby', query: [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'top' => min(100, $limit * 2),
                'filter' => 'ALL',
                'search-radius' => $radius,
            ]);
            if (! $result['successful']) {
                throw new RuntimeException('Не удалось получить список отделений Почты России.');
            }
            $offices = $this->mapOffices((array) $result['data'], $limit);
            if ($offices) {
                break;
            }
        }

        if ($offices) {
            Cache::put($key, $offices, now()->addHours(6));
        }

        return $offices;
    }

    /** Индекс по адресу (нормализация Почты) или null, если адрес не распознан уверенно. */
    public function normalizeIndex(string $city, string $address): ?string
    {
        $original = trim($city.', '.$address, ' ,');
        if ($original === '') {
            return null;
        }

        $key = 'pochta:index:'.md5(mb_strtolower($original));
        if (($cached = Cache::get($key)) !== null) {
            return $cached ?: null;
        }

        $result = $this->client->request('POST', '/1.0/clean/address', [['id' => 'a1', 'original-address' => $original]]);
        if (! $result['successful']) {
            throw new RuntimeException('Не удалось проверить адрес в Почте России.');
        }

        $first = $result['data'][0] ?? [];
        $index = filled($first['index'] ?? null) && in_array($first['quality-code'] ?? null, self::ADDRESS_QUALITY_OK, true)
            ? (string) $first['index']
            : null;
        Cache::put($key, $index ?? '', now()->addDay());

        return $index;
    }

    /**
     * Приводит данные доставки заказа к адресу, который менеджер исправил в админке.
     *
     * Если менеджер сам ввёл новый индекс, берём его. Иначе при смене города или адреса прежний
     * индекс неверен: для курьера ищем по новому адресу, для отделения и международных очищаем
     * (пустой индекс заметят, чужой — нет). Цена доставки не меняется.
     *
     * $before и $after: country, city, address, postal_code.
     *
     * @return array{delivery: array<string, mixed>, postal_code: ?string}|null null — место не менялось
     */
    public function adjustToAddress(array $delivery, array $before, array $after): ?array
    {
        $value = fn (array $source, string $key) => trim((string) (array_key_exists($key, $source) ? $source[$key] : ($before[$key] ?? '')));
        // Окно доставки в админке сохраняет страну кодом («RU»), а в заказе с сайта она названием («Россия»):
        // это одна и та же страна, иначе любое сохранение стирало отделение и индекс.
        $comparable = fn (array $source, string $key) => $key === 'country'
            ? $this->countryKey($value($source, $key))
            : mb_strtolower($value($source, $key));
        $changed = fn (string $key) => $comparable($before, $key) !== $comparable($after, $key);

        $placeChanged = $changed('country') || $changed('city') || $changed('address');
        if (! $placeChanged && ! $changed('postal_code')) {
            return null;
        }

        $type = $delivery['delivery_type'] ?? null;
        $city = $value($after, 'city');
        $address = $value($after, 'address');

        $index = $changed('postal_code') ? (preg_replace('/\s+/', '', $value($after, 'postal_code')) ?: null) : null;
        if (! $changed('postal_code') && $type === 'courier') {
            try {
                $index = $this->normalizeIndex($city, $address);
            } catch (RuntimeException) {
                $index = null;
            }
        }

        $destination = (array) ($delivery['destination'] ?? []);
        if ($changed('country')) {
            unset($destination['country_code']);
        }
        $delivery['destination'] = array_filter(
            array_merge($destination, ['index' => $index, 'city' => $city, 'address' => $address]),
            fn ($item) => $item !== null && $item !== '',
        );

        if ($type === 'office') {
            // Отделение определяется индексом; без нового индекса выбранное раньше уже не подходит.
            if ($index !== null) {
                $delivery['pvz'] = ['code' => $index, 'address' => $address];
            } else {
                unset($delivery['pvz']);
            }
        }

        return ['delivery' => $delivery, 'postal_code' => $index];
    }

    /** Страна для сравнения: код по названию из справочника, иначе сама строка (в нижнем регистре). */
    private function countryKey(string $country): string
    {
        $code = mb_strlen($country) > 2 ? Country::query()->where('name', $country)->value('code') : null;

        return mb_strtolower((string) ($code ?? $country));
    }

    private function domesticIndex(array $method, array $destination): string
    {
        $index = preg_replace('/\D/', '', (string) ($destination['index'] ?? ''));
        if (($method['delivery_type'] ?? null) === 'office') {
            if (strlen($index) !== 6) {
                throw new InvalidArgumentException('Выберите отделение Почты России.');
            }

            return $index;
        }

        if (strlen($index) === 6) {
            return $index;
        }

        $address = trim((string) ($destination['address'] ?? ''));
        if ($address === '') {
            throw new InvalidArgumentException('Для курьерской доставки укажите населённый пункт и адрес.');
        }

        $found = $this->normalizeIndex((string) ($destination['city'] ?? ''), $address);
        if (! $found) {
            throw new InvalidArgumentException('Не удалось определить почтовый индекс по адресу. Уточните город, улицу и дом.');
        }

        return $found;
    }

    private function countryCode(string $iso2): int
    {
        $code = config('pochta_countries.'.$iso2);
        if (! $code) {
            throw new InvalidArgumentException('Доставка Почтой России в выбранную страну недоступна.');
        }

        return (int) $code;
    }

    /** @return array{mass:int,dimension:array{length:int,width:int,height:int},declared:int} */
    private function parcel(array $items): array
    {
        $fallback = $this->settings['default_package'] ?? [];
        $measurements = array_map(fn (array $item) => CdekParcel::measure($item, $fallback), array_values($items));

        return [
            'mass' => max(1, (int) collect($measurements)->sum(fn (array $m) => $m['weight'] * $m['quantity'])),
            'dimension' => CdekParcel::dimensions($measurements),
            // Копейки: API Почты принимает объявленную ценность целым числом в копейках.
            'declared' => (int) round(collect($items)->sum(fn (array $item) => (float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1))) * 100),
        ];
    }

    private function tariffPayload(array $product, array $parcel, array $target, bool $international): array
    {
        $declared = ! $international && ($product['declared'] ?? false) && $parcel['declared'] > 0;

        $payload = $target + [
            'mail-type' => $product['mail_type'],
            'mail-category' => $declared ? 'WITH_DECLARED_VALUE' : ($product['category'] ?? 'ORDINARY'),
            'mass' => $parcel['mass'],
            'dimension' => $parcel['dimension'],
        ];
        if ($declared) {
            $payload['declared-value'] = $parcel['declared'];
        }
        if ($product['courier'] ?? false) {
            $payload['courier'] = true;
        }
        if ($international) {
            $payload['entries-type'] = 'SALE_OF_GOODS';
            $payload['transport-type'] = $product['transport'] ?? 'SURFACE';
        }
        if (filled($this->settings['index_from'] ?? null)) {
            $payload['index-from'] = (string) $this->settings['index_from'];
        }

        return $payload;
    }

    /**
     * Один тариф или null (вид отправления недоступен для адреса).
     * Ошибка API возвращается как ['error' => 'unavailable'|'rejected'].
     */
    private function tariff(array $product, array $payload): ?array
    {
        $key = 'pochta:tariff:'.sha1(json_encode($payload));
        $minutes = (int) ($this->settings['tariff_cache_minutes'] ?? 10);
        if ($minutes > 0 && ($cached = Cache::get($key)) !== null) {
            return $cached ?: null;
        }

        $result = $this->client->request('POST', '/1.0/tariff', $payload);
        if (! $result['successful']) {
            // 4xx — Почта отклонила параметры для этого вида отправления (в т.ч. неверный индекс);
            // 5xx и обрыв связи — временная недоступность.
            return ['error' => $result['status'] >= 500 ? 'unavailable' : 'rejected'];
        }

        $data = (array) $result['data'];
        $kopecks = (int) ($data['total-rate'] ?? 0) + (int) ($data['total-vat'] ?? 0);
        // Нулевая стоимость — API не знает такого тарифа для этого направления (так отвечает для почтоматов и EMS в Эстонию).
        $row = $kopecks > 0 ? $this->row($product, $payload, $kopecks, (array) ($data['delivery-time'] ?? [])) : null;

        if ($minutes > 0) {
            Cache::put($key, $row ?? '', now()->addMinutes($minutes));
        }

        return $row;
    }

    private function row(array $product, array $payload, int $kopecks, array $time): array
    {
        $max = (int) ($time['max-days'] ?? 0);
        $min = (int) ($time['min-days'] ?? $max);

        return [
            'tariff_code' => $product['mail_type'],
            'tariff_name' => $product['name'],
            'display_name' => $product['name'],
            'mail_type' => $product['mail_type'],
            'mail_category' => $payload['mail-category'],
            'delivery_mode' => ($product['courier'] ?? false) ? 'courier' : 'office',
            // Целые рубли, округление вверх: карточка тарифа и строка «Доставка» в итогах показывают одно число.
            'price' => (float) ceil($kopecks / 100),
            'currency' => 'RUB',
            'period' => ['min' => $min, 'max' => $max],
        ];
    }

    private function officesByAddress(string $city, int $limit): array
    {
        $found = $this->client->request('GET', '/postoffice/1.0/by-address', query: ['address' => $city, 'top' => 10]);
        if (! $found['successful']) {
            throw new RuntimeException('Не удалось получить список отделений Почты России.');
        }

        $offices = [];
        foreach (array_slice((array) ($found['data']['postoffices'] ?? []), 0, 10) as $index) {
            $one = $this->client->request('GET', '/postoffice/1.0/'.$index);
            if ($one['successful'] && is_array($one['data'])) {
                $offices[] = $one['data'] + ['postal-code' => (string) $index];
            }
        }

        return $this->mapOffices($offices, $limit);
    }

    private function mapOffices(array $raw, int $limit): array
    {
        $offices = [];
        foreach ($raw as $office) {
            if (! in_array($office['type-code'] ?? null, self::OFFICE_TYPES, true)
                || ($office['is-closed'] ?? false) || ($office['is-temporary-closed'] ?? false) || ($office['is-private-category'] ?? false)
                || ! filled($office['postal-code'] ?? null) || ! isset($office['latitude'], $office['longitude'])) {
                continue;
            }
            $offices[] = [
                'code' => (string) $office['postal-code'],
                'type' => $office['type-code'],
                'address' => trim($office['address-source'] ?? ''),
                'latitude' => (float) $office['latitude'],
                'longitude' => (float) $office['longitude'],
                // Расстояние API отдаёт в метрах.
                'distance_km' => isset($office['distance']) ? round($office['distance'] / 1000, 1) : null,
                'hours' => $this->hours((array) ($office['working-hours'] ?? [])),
            ];
            if (count($offices) >= $limit) {
                break;
            }
        }

        return $offices;
    }

    /** «Пн–Пт 09:00–21:00, Сб 10:00–18:00»: соседние дни с одинаковым временем объединяются. */
    private function hours(array $schedule): string
    {
        $days = [];
        foreach ($schedule as $day) {
            $id = (int) ($day['weekday-id'] ?? 0);
            if (isset(self::WEEKDAYS[$id]) && filled($day['begin-worktime'] ?? null) && filled($day['end-worktime'] ?? null)) {
                $days[$id] = substr($day['begin-worktime'], 0, 5).'–'.substr($day['end-worktime'], 0, 5);
            }
        }
        ksort($days);

        $groups = [];
        foreach ($days as $id => $time) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['time'] === $time && $groups[$last]['to'] === $id - 1) {
                $groups[$last]['to'] = $id;
            } else {
                $groups[] = ['from' => $id, 'to' => $id, 'time' => $time];
            }
        }

        return implode(', ', array_map(
            fn (array $g) => ($g['from'] === $g['to'] ? self::WEEKDAYS[$g['from']] : self::WEEKDAYS[$g['from']].'–'.self::WEEKDAYS[$g['to']]).' '.$g['time'],
            $groups,
        ));
    }

    private function checkoutItem(array $item): array
    {
        $model = $item['model'] ?? null;
        $product = $model?->product;

        return [
            'name' => $item['name'] ?? $model?->name ?? $product?->name ?? 'Товар',
            'price' => (float) ($item['final_price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'weight' => CdekParcel::firstPositive($model?->weight, $product?->weight),
            'length' => CdekParcel::firstPositive($model?->length, $product?->length),
            'width' => CdekParcel::firstPositive($model?->width, $product?->width),
            'height' => CdekParcel::firstPositive($model?->height, $product?->height),
        ];
    }

    private function withoutEmptyOverrides(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = $this->withoutEmptyOverrides($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === null || $value === '') {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function calculateRate(Order $order): Collection
    {
        return collect();
    }

    public function createShipment(Order $order): Shipment
    {
        throw new RuntimeException('Оформление отправлений Почты России пока не поддерживается.');
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        return [];
    }

    public function cancelShipment(Shipment $shipment): bool
    {
        return false;
    }

    public function printLabel(Shipment $shipment): string
    {
        return '';
    }
}
