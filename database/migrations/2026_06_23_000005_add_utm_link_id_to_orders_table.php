<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Атрибуция заказа к UTM-метке (решение #2, вариант A).
        // Заполняется из куки utm_link_id при оформлении заказа.
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'utm_link_id')) {
                $table->foreignId('utm_link_id')
                    ->nullable()
                    ->after('utm_term')
                    ->constrained('utm_links')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'utm_link_id')) {
                $table->dropForeign(['utm_link_id']);
                $table->dropColumn('utm_link_id');
            }
        });
    }
};
