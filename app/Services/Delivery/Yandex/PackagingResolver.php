<?php

namespace App\Services\Delivery\Yandex;

class PackagingResolver
{
    /** @return array<int, array{weight: float, size: array{length: float, width: float, height: float}, quantity: int}> */
    public function resolve(array $items): array
    {
        $default = config('services.yandex_delivery.packaging.default');
        $threshold = (int) config('services.yandex_delivery.packaging.bulk_threshold', 5);
        $total = array_sum(array_map(fn (array $item) => max(1, (int) ($item['quantity'] ?? 1)), $items));

        return array_map(function (array $item) use ($default, $threshold, $total): array {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $size = $item['size'] ?? [];
            $weight = (float) ($item['weight'] ?? $default['weight']);
            $length = (float) ($size['length'] ?? $item['length'] ?? $default['length']);
            $width = (float) ($size['width'] ?? $item['width'] ?? $default['width']);
            $height = (float) ($size['height'] ?? $item['height'] ?? $default['height']);

            if ($total > $threshold) {
                $factor = $quantity ** (1 / 3);
                $weight *= $quantity;
                $length *= $factor;
                $width *= $factor;
                $height *= $factor;
                $quantity = 1;
            }

            return [
                'weight' => round($weight, 3),
                'size' => ['length' => round($length, 3), 'width' => round($width, 3), 'height' => round($height, 3)],
                'quantity' => $quantity,
            ];
        }, $items);
    }
}
