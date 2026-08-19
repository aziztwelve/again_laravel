<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // UUID документа "Заказ покупателя" в МойСклад. Заполняется после
            // первой успешной выгрузки (см. App\Jobs\SyncOrderToMoySkladJob).
            $table->string('moysklad_order_uuid')->nullable()->after('tracking_number');
            $table->timestamp('moysklad_synced_at')->nullable()->after('moysklad_order_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['moysklad_order_uuid', 'moysklad_synced_at']);
        });
    }
};
