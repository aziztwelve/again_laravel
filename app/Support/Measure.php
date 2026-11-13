<?php

namespace App\Support;

/**
 * Общие правила для веса и габаритов товаров при передаче в службы доставки
 * (СДЭК, Яндекс.Доставка).
 */
final class Measure
{
    /**
     * Вес товара в граммах или null, если веса нет.
     *
     * В базе веса лежат в разных единицах: из МойСклад приходят граммы
     * (68…233), а введённые вручную — килограммы (0.1…0.6). Реальный вес
     * товара меньше 5 г невозможен, поэтому значения до 5 считаем килограммами.
     */
    public static function grams(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        $grams = (float) $value < 5 ? (float) $value * 1000 : (float) $value;

        return max(1, (int) round($grams));
    }

    /**
     * Первое положительное число из списка или null.
     *
     * Нельзя использовать `$a ?: $b`: decimal-касты Eloquent отдают строку
     * «0.000», а она в PHP истинна, и запасное значение не подставлялось
     * (вес варианта 0.000 превращался в 1 г вместо веса товара).
     */
    public static function firstPositive(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        return null;
    }
}
