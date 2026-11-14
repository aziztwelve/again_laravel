<?php

namespace App\Services\Notifications;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Решает, можно ли отправить покупателю уведомление о смене статуса доставки (см. config/delivery_notifications.php).
 * Неизвестное время статуса = не отправляем: безопаснее промолчать, чем отправить запоздалое сообщение.
 */
class DeliveryNotificationGuard
{
    public static function allows(CarbonInterface|string|int|null $statusAt, ?CarbonInterface $now = null): bool
    {
        $at = self::toCarbon($statusAt);
        if ($at === null) {
            return false;
        }

        $notBefore = config('delivery_notifications.not_before');
        if (filled($notBefore) && $at->lt(Carbon::parse($notBefore))) {
            return false;
        }

        $maxAge = (int) config('delivery_notifications.max_age_hours', 24);
        if ($maxAge > 0 && $at->lt(($now ?? now())->copy()->subHours($maxAge))) {
            return false;
        }

        return true;
    }

    private static function toCarbon(CarbonInterface|string|int|null $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof CarbonInterface) {
                return $value;
            }

            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
