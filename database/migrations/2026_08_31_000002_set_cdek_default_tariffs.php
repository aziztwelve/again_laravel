<?php

use App\Models\DeliveryServiceSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'cdek']);
        $settings = $record->settings ?? [];

        // Согласованный набор из профиля InSales: два базовых тарифа и
        // склад-постамат. Остальные доступны в списке, но не показываются
        // покупателю, пока менеджер не выберет их в настройках.
        $settings['tariff_codes'] = [136, 137, 368];
        $record->update(['settings' => $settings]);
    }

    public function down(): void
    {
        $record = DeliveryServiceSetting::query()->where('service_name', 'cdek')->first();
        if (! $record) return;

        $settings = $record->settings ?? [];
        $settings['tariff_codes'] = [];
        $record->update(['settings' => $settings]);
    }
};
