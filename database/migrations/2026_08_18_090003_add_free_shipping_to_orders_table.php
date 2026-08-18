<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Следы бесплатной доставки в заказе (см. docs/tasks/free-shipping.md):
 *  - free_shipping_rule_id — какое правило обнулило доставку;
 *  - delivery_cost_original — цена тарифа до обнуления (аналитика «сколько
 *    подарили на доставке»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'free_shipping_rule_id')) {
                $table->foreignId('free_shipping_rule_id')
                    ->nullable()
                    ->after('delivery_cost')
                    ->constrained('free_shipping_rules')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'delivery_cost_original')) {
                $table->decimal('delivery_cost_original', 12, 2)
                    ->nullable()
                    ->after('free_shipping_rule_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'free_shipping_rule_id')) {
                $table->dropForeign(['free_shipping_rule_id']);
                $table->dropColumn('free_shipping_rule_id');
            }

            if (Schema::hasColumn('orders', 'delivery_cost_original')) {
                $table->dropColumn('delivery_cost_original');
            }
        });
    }
};
