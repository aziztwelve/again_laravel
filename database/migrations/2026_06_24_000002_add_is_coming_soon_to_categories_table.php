<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Категория «Скоро в продаже»: авто-наполнение товарами
            // is_active = true AND stock_quantity = 0 (минуя pivot, как is_new_product).
            $table->boolean('is_coming_soon')->default(false)->after('is_new_product');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_coming_soon');
        });
    }
};
