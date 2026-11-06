<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'vat')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // Ставка НДС товара (%) из МойСклад; null — не задана / без НДС.
            $table->unsignedTinyInteger('vat')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'vat')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('vat');
            });
        }
    }
};
