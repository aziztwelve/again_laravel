<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Чек по умолчанию ПРОБИВАЕТСЯ: флаг «Чек не пробивать» менеджер ставит в заказе вручную.
// Меняется только значение по умолчанию для НОВЫХ заказов, существующие заказы не затрагиваются.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('no_receipt')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('no_receipt')->default(true)->change();
        });
    }
};
