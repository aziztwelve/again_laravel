<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['discounts', 'promo_codes', 'segments'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'customer_type')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('customer_type', 20)
                    ->default('all')
                    ->after('is_active')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['discounts', 'promo_codes', 'segments'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'customer_type')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['customer_type']);
                $table->dropColumn('customer_type');
            });
        }
    }
};
