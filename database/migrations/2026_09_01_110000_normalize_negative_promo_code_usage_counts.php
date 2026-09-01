<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('promo_codes')
            ->where('times_used', '<', 0)
            ->update(['times_used' => 0]);
    }

    public function down(): void
    {
        // Нормализация счётчика необратима и намеренно не откатывается.
    }
};
