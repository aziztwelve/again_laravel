<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryServiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YandexDeliverySettingsController extends Controller
{
    private const DEFAULT_DATE_OFFSET_DAYS = 2;

    public function show(): JsonResponse
    {
        $settings = DeliveryServiceSetting::query()
            ->where('service_name', 'yandex')
            ->value('settings') ?? [];

        return response()->json([
            'success' => true,
            'settings' => [
                'delivery_date_offset_days' => (int) ($settings['delivery_date_offset_days'] ?? self::DEFAULT_DATE_OFFSET_DAYS),
                'api_token_configured' => filled($settings['token'] ?? config('services.yandex_delivery.token')),
                'widget_code' => (string) ($settings['widget_code'] ?? ''),
                'api_url' => (string) ($settings['api_url'] ?? ''),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_date_offset_days' => ['required', 'integer', 'min:0', 'max:30'],
            'token' => ['nullable', 'string', 'max:2048'],
            'widget_code' => ['nullable', 'string', 'max:255'],
            'api_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'yandex']);
        $settings = $record->settings ?? [];
        $settings['delivery_date_offset_days'] = (int) $data['delivery_date_offset_days'];
        // Не возвращаем секрет в API и не стираем его, если поле оставили пустым.
        if (filled($data['token'] ?? null)) $settings['token'] = $data['token'];
        $settings['widget_code'] = (string) ($data['widget_code'] ?? '');
        $settings['api_url'] = (string) ($data['api_url'] ?? '');
        $record->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Яндекс.Доставки сохранены',
            'settings' => [
                'delivery_date_offset_days' => $settings['delivery_date_offset_days'],
                'api_token_configured' => filled($settings['token'] ?? config('services.yandex_delivery.token')),
                'widget_code' => $settings['widget_code'],
                'api_url' => $settings['api_url'],
            ],
        ]);
    }
}
