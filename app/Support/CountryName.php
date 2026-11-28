<?php

namespace App\Support;

use App\Models\Country;

/**
 * Страна в адресе заказа. Сайт записывает название («Россия»), а формы админки присылают
 * двухбуквенный код («RU»): он попадал в МойСклад, письма и на страницу заказа.
 */
final class CountryName
{
    /** Название по коду из справочника стран; всё остальное — как есть. */
    public static function fromInput(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^[A-Za-z]{2}$/', trim($value))) {
            return $value;
        }

        return Country::query()->where('code', strtoupper(trim($value)))->value('name') ?? $value;
    }

    /** «RU» и «Россия» — одна и та же страна. */
    public static function same(?string $first, ?string $second): bool
    {
        $key = fn (?string $value) => mb_strtolower(trim((string) self::fromInput($value)));

        return $key($first) === $key($second);
    }
}
