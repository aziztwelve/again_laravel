<?php

namespace App\Services\Delivery\Cdek;

use App\Support\Measure;

/**
 * Вес и габариты посылки для СДЭК.
 *
 * Вынесено из CdekDeliveryService, чтобы расчёт можно было проверять тестами
 * и чтобы одни и те же правила работали и в расчёте тарифа, и в заявке.
 */
final class CdekParcel
{
    /** Запас по объёму на неплотную укладку нескольких коробок в одно место. */
    private const PACKING_SLACK = 1.15;

    /** Вес в граммах или null (см. Measure::grams). */
    public static function grams(mixed $value): ?int
    {
        return Measure::grams($value);
    }

    /** Первое положительное число или null (см. Measure::firstPositive). */
    public static function firstPositive(mixed ...$values): ?float
    {
        return Measure::firstPositive(...$values);
    }

    /**
     * Измерения позиции: вес в граммах, габариты в см (с округлением вверх,
     * чтобы не занижать размер) и количество. Недостающее берётся из default_package.
     *
     * @return array{weight:int,length:int,width:int,height:int,quantity:int}
     */
    public static function measure(array $item, array $fallback): array
    {
        $dimension = fn (string $key, int $default): int => max(1, (int) ceil(
            self::firstPositive($item[$key] ?? null, $fallback[$key] ?? null) ?? $default,
        ));

        return [
            'weight' => self::grams($item['weight'] ?? null) ?? max(1, (int) ($fallback['weight'] ?? 500)),
            'length' => $dimension('length', 20),
            'width' => $dimension('width', 10),
            'height' => $dimension('height', 10),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ];
    }

    /**
     * Габариты одного места на все позиции.
     *
     * Высоты не складываем в стопку (5 позиций давали 162 см, и СДЭК отклонял
     * заявку для постамата). Ищем коробку, в которую каждая позиция помещается
     * в какой-то ориентации и которая вмещает суммарный объём: стороны берутся
     * по максимумам позиций, а затем наименьшая сторона растёт, пока объём не
     * поместится. Результат по убыванию: длина ≥ ширина ≥ высота.
     *
     * @param  array<int, array{length:int,width:int,height:int,quantity:int}>  $measurements
     * @return array{length:int,width:int,height:int}
     */
    public static function dimensions(array $measurements): array
    {
        $box = [1, 1, 1];
        $volume = 0;
        $units = 0;

        foreach ($measurements as $m) {
            $sides = [$m['length'], $m['width'], $m['height']];
            rsort($sides);
            foreach ($sides as $i => $side) {
                $box[$i] = max($box[$i], $side);
            }
            $volume += $m['length'] * $m['width'] * $m['height'] * $m['quantity'];
            $units += $m['quantity'];
        }

        // Коробки не укладываются без зазоров: для нескольких единиц берём запас по объёму.
        // Одна единица остаётся в своей коробке без изменений.
        $required = $units > 1 ? $volume * self::PACKING_SLACK : $volume;

        // Наименьшая сторона растёт на 1 см, пока коробка не вместит нужный объём.
        for ($guard = 0; $box[0] * $box[1] * $box[2] < $required && $guard < 5000; $guard++) {
            $smallest = array_search(min($box), $box, true);
            $box[$smallest]++;
        }

        rsort($box);

        return ['length' => $box[0], 'width' => $box[1], 'height' => $box[2]];
    }

    /**
     * Почему посылка не поместится в постамат, или null.
     *
     * У постамата СДЭК в `dimensions` перечислены размеры ячеек; посылка
     * подходит, если хотя бы в одну ячейку она помещается (стороны сравниваются
     * по возрастанию). `weight_max` — предельный вес в кг. Для ПВЗ без ячеек
     * ограничений здесь не проверяем.
     *
     * @param  array{length:int,width:int,height:int}  $dimensions
     */
    public static function postamatFitError(array $point, int $weightGrams, array $dimensions): ?string
    {
        if (strtoupper((string) ($point['type'] ?? '')) !== 'POSTAMAT') {
            return null;
        }

        $maxKg = (float) ($point['weight_max'] ?? 0);
        if ($maxKg > 0 && $weightGrams / 1000 > $maxKg) {
            return sprintf('Вес посылки (%s кг) больше допустимого для выбранного постамата (%s кг). Выберите другой постамат или пункт выдачи.', self::kg($weightGrams / 1000), self::kg($maxKg));
        }

        $cells = array_values(array_filter(
            (array) ($point['dimensions'] ?? []),
            fn ($cell) => is_array($cell),
        ));
        if ($cells === []) {
            return null;
        }

        $parcel = [$dimensions['length'], $dimensions['width'], $dimensions['height']];
        sort($parcel);

        foreach ($cells as $cell) {
            $sides = [(float) ($cell['width'] ?? 0), (float) ($cell['height'] ?? 0), (float) ($cell['depth'] ?? 0)];
            sort($sides);
            if ($parcel[0] <= $sides[0] && $parcel[1] <= $sides[1] && $parcel[2] <= $sides[2]) {
                return null;
            }
        }

        return sprintf('Посылка (%d×%d×%d см) не помещается в ячейки выбранного постамата. Выберите другой постамат или пункт выдачи.', ...array_reverse($parcel));
    }

    private static function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
