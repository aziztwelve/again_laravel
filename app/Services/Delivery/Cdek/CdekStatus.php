<?php

namespace App\Services\Delivery\Cdek;

use App\Models\ShipmentStatus;

/**
 * Перевод статусов СДЭК во внутренние статусы отправления.
 *
 * Список закрытый: код, которого здесь нет, НЕ считается «в пути». Раньше всё
 * неизвестное превращалось в IN_TRANSIT, а наблюдатель переводил по нему заказ
 * в «Отгружен» — так заказ с отклонённой заявкой (INVALID) уходил в «Отгружен»,
 * хотя посылки в СДЭК не было. Теперь неизвестный код оставляет заказ как есть
 * (статус «В обработке») и пишется в лог, чтобы код можно было добавить сюда.
 */
final class CdekStatus
{
    /** Заявка отклонена СДЭК: посылки нет, нужны действия менеджера. */
    public const FAILURE_CODES = ['INVALID'];

    private const NEW = ['CREATED', 'ACCEPTED'];

    private const DELIVERED = ['DELIVERED'];

    private const RETURNED = ['NOT_DELIVERED', 'RETURNED_TO_SENDER'];

    /** Заявку удалили (у нас или в кабинете СДЭК). */
    private const CANCELLED = ['REMOVED'];

    /** Посылка у СДЭК: приём, перевозка, склады, ПВЗ/постамат, таможня. */
    private const IN_TRANSIT = [
        'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'READY_FOR_SHIPMENT_IN_SENDER_CITY',
        'RETURNED_TO_SENDER_CITY_WAREHOUSE', 'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY',
        'SENT_TO_TRANSIT_CITY', 'ACCEPTED_IN_TRANSIT_CITY', 'ACCEPTED_AT_TRANSIT_WAREHOUSE',
        'RETURNED_TO_TRANSIT_WAREHOUSE', 'READY_FOR_SHIPMENT_IN_TRANSIT_CITY',
        'TAKEN_BY_TRANSPORTER_FROM_TRANSIT_CITY', 'SENT_TO_SENDER_CITY', 'SENT_TO_RECIPIENT_CITY',
        'ACCEPTED_IN_SENDER_CITY', 'ACCEPTED_IN_RECIPIENT_CITY', 'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE',
        'ACCEPTED_AT_PICK_UP_POINT', 'TAKEN_BY_COURIER', 'RETURNED_TO_RECIPIENT_CITY_WAREHOUSE',
        'IN_CUSTOMS_INTERNATIONAL', 'SHIPPED_TO_DESTINATION', 'PASSED_TO_TRANSIT_CARRIER',
        'IN_CUSTOMS_LOCAL', 'CUSTOMS_COMPLETE', 'POSTOMAT_POSTED', 'POSTOMAT_SEIZED', 'POSTOMAT_RECEIVED',
    ];

    public static function isFailure(string $code): bool
    {
        return in_array(strtoupper($code), self::FAILURE_CODES, true);
    }

    /** Известен ли код (для предупреждения в логе о новых статусах СДЭК). */
    public static function isKnown(string $code): bool
    {
        $code = strtoupper($code);

        return self::isFailure($code) || in_array($code, [
            ...self::NEW, ...self::DELIVERED, ...self::RETURNED, ...self::CANCELLED, ...self::IN_TRANSIT,
        ], true);
    }

    public static function internal(string $code): string
    {
        $code = strtoupper($code);

        return match (true) {
            self::isFailure($code) => ShipmentStatus::FAILED,
            in_array($code, self::DELIVERED, true) => ShipmentStatus::DELIVERED,
            in_array($code, self::RETURNED, true) => ShipmentStatus::RETURNED,
            in_array($code, self::NEW, true) => ShipmentStatus::NEW,
            in_array($code, self::CANCELLED, true) => ShipmentStatus::CANCELLED,
            in_array($code, self::IN_TRANSIT, true) => ShipmentStatus::IN_TRANSIT,
            default => ShipmentStatus::PROCESSING,
        };
    }
}
