<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Связь заказа с корзиной из которой он оформлен (фича «Брошенная корзина»,
     * решение #1 — см. docs/tasks/abandoned-cart.md). FK на таблицу `cart`
     * (singular, не легаси `carts`). Nullable: гостевые и несинхронизированные
     * корзины оставляют cart_id = null. onDelete=set null — корзина расходная.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cart_id')) {
                $table->foreignId('cart_id')
                    ->nullable()
                    ->constrained('cart')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cart_id')) {
                $table->dropForeign(['cart_id']);
                $table->dropColumn('cart_id');
            }
        });
    }
};
