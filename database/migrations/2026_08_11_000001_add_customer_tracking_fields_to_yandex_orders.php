<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yandex_orders', function (Blueprint $table) {
            $table->string('customer_status')->nullable()->index()->after('internal_status');
            $table->string('tracking_number')->nullable()->after('tracking_url');
        });
    }

    public function down(): void
    {
        Schema::table('yandex_orders', function (Blueprint $table) {
            $table->dropIndex(['customer_status']);
            $table->dropColumn(['customer_status', 'tracking_number']);
        });
    }
};
