<?php

use App\Models\DeliveryServiceSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'yandex']);
        $settings = $record->settings ?? [];

        if (! array_key_exists('delivery_date_offset_days', $settings)) {
            $settings['delivery_date_offset_days'] = 2;
            $record->update(['settings' => $settings]);
        }
    }

    public function down(): void
    {
        $record = DeliveryServiceSetting::query()->where('service_name', 'yandex')->first();
        if (! $record) return;

        $settings = $record->settings ?? [];
        unset($settings['delivery_date_offset_days']);
        $record->update(['settings' => $settings]);
    }
};
