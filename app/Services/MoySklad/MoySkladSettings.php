<?php

namespace App\Services\MoySklad;

use App\Models\DeliveryServiceSetting;

/**
 * Доступ к настройкам интеграции МойСклад.
 *
 * Сервисы МойСклад бросают исключение, если настроек нет, — это осознанно
 * для админских операций («настройте сервис в админке»). Но фоновая
 * синхронизация заказов не должна из-за этого падать в failed_jobs и уж тем
 * более ломать оформление заказа клиентом, поэтому фоновым задачам нужна
 * дешёвая проверка «интеграция вообще настроена?» без создания сервиса.
 */
class MoySkladSettings
{
    public const SERVICE_NAME = 'moysklad';

    public static function get(): ?DeliveryServiceSetting
    {
        return DeliveryServiceSetting::query()
            ->where('service_name', self::SERVICE_NAME)
            ->first();
    }

    public static function token(): ?string
    {
        $token = self::get()?->token;

        return filled($token) ? $token : null;
    }

    public static function isConfigured(): bool
    {
        return self::token() !== null;
    }
}
