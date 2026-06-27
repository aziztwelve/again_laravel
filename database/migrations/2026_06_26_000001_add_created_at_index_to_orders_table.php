<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индекс на orders.created_at: аналитика UTM (и не только) фильтрует заказы
     * по периоду (whereBetween created_at) — см. docs/tasks/utm-tracking.md,
     * производительность #7. На больших объёмах ускоряет агрегаты по меткам.
     */
    public function up(): void
    {
        if ($this->indexExists('orders', 'orders_created_at_index')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_index');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('orders', 'orders_created_at_index')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
