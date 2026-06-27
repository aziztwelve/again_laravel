<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Промокод-стимул на последнем шаге цепочки брошенной корзины (фаза 2,
 * см. docs/tasks/abandoned-cart.md). Храним выданный корзине код, чтобы не
 * генерировать его повторно и показывать тот же код во всех касаниях.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            if (! Schema::hasColumn('cart', 'recovery_promo_code')) {
                $table->string('recovery_promo_code')->nullable()->after('recovery_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            if (Schema::hasColumn('cart', 'recovery_promo_code')) {
                $table->dropColumn('recovery_promo_code');
            }
        });
    }
};
