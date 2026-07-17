<?php

namespace App\Services\Delivery\Yandex;

use App\Models\Order;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PayloadBuilder
{
    public function __construct(private PackagingResolver $packaging)
    {
    }

    public function offers(array $source, string $deliveryType, array $items, ?array $destination, ?array $pvzCoordinates, ?string $taxiClass = null): array
    {
        $routePoints = [$this->routePoint(1, $source)];
        $target = $deliveryType === 'pickup'
            ? ['coordinates' => $pvzCoordinates, 'address' => $destination['address'] ?? 'Пункт выдачи Яндекс.Доставки']
            : $destination;
        $routePoints[] = $this->routePoint(2, $target ?? []);

        return [
            'items' => array_map(fn (array $item) => $item + ['pickup_point' => 1, 'dropoff_point' => 2], $this->packaging->resolve($items)),
            'route_points' => $routePoints,
            'requirements' => array_filter(['taxi_classes' => $taxiClass ? [$taxiClass] : null]),
        ];
    }

    public function claim(Order $order, array $source): array
    {
        $data = $order->delivery_data ?? [];
        $destination = $data['destination'] ?? null;
        $pvz = $data['pvz']['coordinates'] ?? null;
        $type = $data['delivery_type'] ?? 'courier';
        $items = $order->items->map(fn ($item) => [
            'quantity' => $item->quantity,
            'name' => $item->legacy_name ?: $item->product?->name ?: 'Товар',
            'weight' => $item->variant?->weight ?: $item->product?->weight,
            'size' => [
                'length' => $item->variant?->length ?: $item->product?->length,
                'width' => $item->variant?->width ?: $item->product?->width,
                'height' => $item->variant?->height ?: $item->product?->height,
            ],
        ])->all();

        $payload = $this->offers($source, $type, $items, $destination, $pvz, $data['tariff_code'] ?? null);
        $payload['route_points'][1]['contact'] = [
            'name' => $order->client?->name ?? 'Покупатель',
            'phone' => $this->phone($order->client?->phone ?? ''),
        ];
        $payload['comment'] = $order->delivery_comment;
        $payload['items'] = array_map(fn (array $item, int $index) => $item + ['title' => $items[$index]['name'], 'cost_value' => '0'], $payload['items'], array_keys($payload['items']));

        return $payload;
    }

    private function routePoint(int $id, array $point): array
    {
        $coordinates = $point['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) !== 2) {
            throw new InvalidArgumentException('Для Яндекс.Доставки требуются координаты точки маршрута.');
        }

        return ['id' => $id, 'coordinates' => [(float) $coordinates[0], (float) $coordinates[1]], 'fullname' => $point['address'] ?? 'Адрес не указан'];
    }

    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return strlen($digits) === 10 ? '+7'.$digits : '+'.ltrim($digits, '+');
    }
}
