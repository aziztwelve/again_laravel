<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Накопительные (стекируемые) подарки: флаг управляет тем, складывается ли
     * акция с другими стекируемыми акциями на одном заказе.
     *   true  — акция суммируется с другими стекируемыми (накопительные подарки);
     *   false — акция «эксклюзивная»: применяется одна по приоритету (старое поведение).
     * default = false — поведение существующих акций не меняется.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('is_stackable')
                ->default(false)
                ->after('allow_promo_codes')
                ->comment('Суммируется ли акция с другими стекируемыми акциями');

            $table->index('is_stackable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex(['is_stackable']);
            $table->dropColumn('is_stackable');
        });
    }
};
