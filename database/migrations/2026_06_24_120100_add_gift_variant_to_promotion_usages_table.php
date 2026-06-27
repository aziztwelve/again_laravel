<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Сохраняем выбранный вариант (размер/цвет) подарка в историю применений.
     * Раньше variant подарка нигде в usages не фиксировался.
     */
    public function up(): void
    {
        Schema::table('promotion_usages', function (Blueprint $table) {
            $table->foreignId('gift_product_variant_id')
                ->nullable()
                ->after('gift_product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotion_usages', function (Blueprint $table) {
            $table->dropForeign(['gift_product_variant_id']);
            $table->dropColumn('gift_product_variant_id');
        });
    }
};
