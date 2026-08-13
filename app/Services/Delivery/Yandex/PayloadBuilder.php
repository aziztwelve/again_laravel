<?php

namespace App\Services\Delivery\Yandex;

use App\Models\Order;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Builds requests for Yandex Delivery Platform API (Delivery across Russia). */
class PayloadBuilder
{
    public function offers(array $settings, string $deliveryType, array $items, ?string $pvzId, ?array $destination, array $recipient = [], ?string $operatorRequestId = null): array
    {
        $stationId = $settings['platform_station_id'] ?? null;
        if (! $stationId) {
            throw new InvalidArgumentException('Не настроен склад отгрузки Яндекс.Доставки.');
        }

        $placeBarcode = 'place-'.substr(md5(json_encode($items)), 0, 12);
        $payload = [
            'info' => array_filter([
                'operator_request_id' => $operatorRequestId ?? 'checkout-'.Str::uuid(),
                'merchant_id' => $settings['merchant_id'] ?? null,
            ]),
            'source' => [
                'platform_station' => ['platform_id' => $stationId],
            ],
            'destination' => $this->destination($deliveryType, $pvzId, $destination),
            'items' => array_map(fn (array $item) => $this->item($item, $placeBarcode), $items),
            'places' => [$this->place($items, $placeBarcode)],
            'billing_info' => ['payment_method' => 'already_paid', 'delivery_cost' => 0],
            'recipient_info' => $this->recipient($recipient),
            'last_mile_policy' => $deliveryType === 'pickup' ? 'self_pickup' : 'time_interval',
            'particular_items_refuse' => false,
            'forbid_unboxing' => false,
        ];

        return $payload;
    }

    public function order(Order $order, array $settings): array
    {
        $data = $order->delivery_data ?? [];
        $address = $order->loadMissing('address')->address;
        $items = $order->loadMissing('items.product', 'items.variant')->items->map(fn ($item) => [
            'quantity' => $item->quantity,
            'name' => $item->legacy_name ?: $item->product?->name ?: 'Товар',
            'article' => $item->variant?->sku ?: $item->product?->sku ?: (string) $item->product_id,
            'price' => $item->price,
            'weight' => $item->variant?->weight ?: $item->product?->weight,
            'size' => [
                'length' => $item->variant?->length ?: $item->product?->length,
                'width' => $item->variant?->width ?: $item->product?->width,
                'height' => $item->variant?->height ?: $item->product?->height,
            ],
        ])->all();

        $addressRecipientName = trim(implode(' ', array_filter([
            $address?->recipient_first_name,
            $address?->recipient_middle_name,
            $address?->recipient_last_name,
        ])));
        $recipient = [
            // Получатель заказа может отличаться от клиента, оформившего покупку.
            'name' => $addressRecipientName ?: ($order->client?->name ?? 'Покупатель'),
            'phone' => $address?->recipient_phone ?? $order->client?->phone ?? '',
            'email' => $order->client?->email ?? $order->email ?? null,
        ];

        $payload = $this->offers(
            $settings,
            $data['delivery_type'] ?? 'courier',
            $items,
            $data['pvz']['id'] ?? null,
            $data['destination'] ?? $this->addressDestination($order),
            $recipient,
            (string) ($order->order_number ?: $order->id),
        );
        $payload['info']['comment'] = $order->delivery_comment;

        return $payload;
    }

    private function destination(string $deliveryType, ?string $pvzId, ?array $destination): array
    {
        if ($deliveryType === 'pickup') {
            if (! $pvzId) throw new InvalidArgumentException('Выберите пункт выдачи Яндекс.Доставки.');
            return ['type' => 'platform_station', 'platform_station' => ['platform_id' => $pvzId]];
        }

        $coordinates = $destination['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) !== 2) {
            throw new InvalidArgumentException('Для курьерской доставки требуются координаты адреса.');
        }
        return [
            'type' => 'custom_location',
            'custom_location' => [
                'longitude' => (float) $coordinates[0],
                'latitude' => (float) $coordinates[1],
                'details' => array_filter(['full_address' => $destination['address'] ?? null]),
            ],
        ];
    }

    private function item(array $item, string $placeBarcode): array
    {
        $size = $item['size'] ?? [];
        $article = (string) ($item['article'] ?? '');
        return [
            'count' => max(1, (int) ($item['quantity'] ?? 1)),
            'name' => (string) ($item['name'] ?? 'Товар'),
            'article' => $article,
            // Значение должно ссылаться на barcode из places[].
            'place_barcode' => $placeBarcode,
            'billing_details' => [
                'unit_price' => (float) ($item['price'] ?? 0),
                'assessed_unit_price' => (float) ($item['price'] ?? 0),
            ],
            'physical_dims' => [
                'dx' => $this->dimension($size['length'] ?? null),
                'dy' => $this->dimension($size['width'] ?? null),
                'dz' => $this->dimension($size['height'] ?? null),
            ],
        ];
    }

    private function place(array $items, string $placeBarcode): array
    {
        $weight = array_sum(array_map(fn ($item) => (float) ($item['weight'] ?? 500) * (int) ($item['quantity'] ?? 1), $items));
        return [
            'barcode' => $placeBarcode,
            'physical_dims' => ['weight_gross' => max(1, (int) $weight), 'dx' => 20, 'dy' => 15, 'dz' => 10],
        ];
    }

    private function recipient(array $recipient): array
    {
        $parts = preg_split('/\s+/', trim((string) ($recipient['name'] ?? 'Покупатель')));
        return array_filter([
            'first_name' => $parts[0] ?? 'Покупатель',
            'last_name' => $parts[1] ?? null,
            'phone' => $this->phone((string) ($recipient['phone'] ?? '')),
            'email' => $recipient['email'] ?? null,
        ]);
    }

    private function addressDestination(Order $order): array
    {
        $address = is_array($order->delivery_address) ? $order->delivery_address : [];
        return ['address' => implode(', ', array_filter([$address['city'] ?? null, $address['address'] ?? null]))];
    }

    private function dimension(mixed $value): int
    {
        $value = (float) ($value ?? 0);
        return max(1, (int) ($value > 5 ? $value : $value * 100));
    }

    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10) return '+7'.$digits;
        if (str_starts_with($digits, '8') && strlen($digits) === 11) return '+7'.substr($digits, 1);
        return $digits ? '+'.$digits : '+70000000000';
    }
}
