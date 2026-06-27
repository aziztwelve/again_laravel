<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поля для фичи «Брошенная корзина» (см. docs/tasks/abandoned-cart.md):
     * - recovery_token: уникальный токен для ссылки восстановления корзины из
     *   письма ({SHOP_URL}/cart/restore/{token}). Генерится при пометке abandoned.
     * - abandoned_at: момент перехода корзины в статус abandoned (отдельно от
     *   updated_at, который меняется при правках позиций). База для офсетов
     *   цепочки напоминаний (24ч / 72ч).
     */
    public function up(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            if (! Schema::hasColumn('cart', 'recovery_token')) {
                $table->string('recovery_token')->nullable()->unique()->after('status');
            }
            if (! Schema::hasColumn('cart', 'abandoned_at')) {
                $table->timestamp('abandoned_at')->nullable()->after('ordered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            if (Schema::hasColumn('cart', 'recovery_token')) {
                $table->dropUnique(['recovery_token']);
                $table->dropColumn('recovery_token');
            }
            if (Schema::hasColumn('cart', 'abandoned_at')) {
                $table->dropColumn('abandoned_at');
            }
        });
    }
};
