<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->boolean('is_manually_disabled')->default(false)->after('is_active');
        });

        // До появления явного флага невозможно отличить старую выключенную
        // скидку от ожидающей автозапуска. Безопасный вариант — сохранить
        // выключенными все уже начавшиеся скидки: включить их можно вручную.
        DB::table('discounts')
            ->where('is_active', false)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->update(['is_manually_disabled' => true]);
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn('is_manually_disabled');
        });
    }
};
