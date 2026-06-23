<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Помечаем заказы, к которым админ применил «ручную» скидку через
 * кнопку «Скидка» в OrderView. Без этого фронт не может отличить
 * авто-скидку (от привязки Product↔Discount) от ручного применения
 * и не имеет id для отрисовки блока «Применена скидка» / «Снять».
 *
 * После миграции:
 *  - applied_discount_id = NULL  → никакой ручной скидки нет, фронт не показывает блок
 *  - applied_discount_id = id    → админ применил Discount#id, блок «Снять» работает
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('applied_discount_id')
                ->nullable()
                ->after('promo_code_id')
                ->constrained('discounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_discount_id');
        });
    }
};
