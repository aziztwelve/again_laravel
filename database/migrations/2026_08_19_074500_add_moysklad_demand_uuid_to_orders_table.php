<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // UUID документа «Отгрузка» (demand) в МойСклад. Заполняется
            // при физическом списании товара по заказу (см.
            // App\Services\MoySklad\DemandService::shipOrder()).
            $table->string('moysklad_demand_uuid')->nullable()->after('moysklad_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('moysklad_demand_uuid');
        });
    }
};
