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
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_date_offset_days' => ['required', 'integer', 'min:0', 'max:30'],
        ]);

        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'yandex']);
        $settings = $record->settings ?? [];
        $settings['delivery_date_offset_days'] = (int) $data['delivery_date_offset_days'];
        $record->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Яндекс.Доставки сохранены',
            'settings' => ['delivery_date_offset_days' => $settings['delivery_date_offset_days']],
        ]);
    }
}
