<?php

use App\Models\DeliveryServiceSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $record = DeliveryServiceSetting::firstOrCreate(['service_name' => 'cdek']);
        $settings = $record->settings ?? [];
        $settings['enabled'] = true;
        $record->update(['settings' => $settings]);
    }

    public function down(): void
    {
        // Do not disable a delivery integration on rollback: this value may
        // have been changed by a manager after the migration was applied.
    }
};
