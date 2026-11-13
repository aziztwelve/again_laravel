<?php

namespace App\Support;

/**
 * Единая проверка и нормализация телефонов получателей.
 *
 * Служба доставки отклоняет заявку с неверным телефоном уже после оплаты
 * («Некорректный телефон получателя»), поэтому формат проверяем заранее: при
 * оформлении заказа и перед отправкой заявки в СДЭК.
 */
final class Phone
{
    /**
     * Цифры номера в международном виде (без «+») или null, если номер неверный.
     *
     *  - российские номера: 11 цифр, начинаются с 7; «8…» заменяется на «7…»,
     *    10 цифр без кода страны дополняются «7»;
     *  - остальные страны: от 10 до 15 цифр (E.164);
     *  - буквы в номере недопустимы (в поле телефона бывает фамилия).
     */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/\p{L}/u', $raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $length = strlen($digits);

        if ($length === 11 && $digits[0] === '8') {
            return '7'.substr($digits, 1);
        }
        if ($length === 10) {
            return '7'.$digits;
        }
        if ($digits !== '' && $digits[0] === '7') {
            return $length === 11 ? $digits : null;
        }

        return $length >= 10 && $length <= 15 ? $digits : null;
    }

    /** Номер для API служб доставки: «+79853531222» или null. */
    public static function e164(?string $raw): ?string
    {
        $digits = self::normalize($raw);

        return $digits === null ? null : '+'.$digits;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
