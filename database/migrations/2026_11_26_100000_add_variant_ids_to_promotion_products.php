<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['promotion_trigger_products', 'promotion_gift_products'];

    /**
     * Варианты (размер/объём/цвет), с которыми товар участвует в акции.
     * NULL — товар участвует целиком (все варианты), поэтому прежние акции не меняются.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('variant_ids')->nullable()->after('product_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('variant_ids');
            });
        }
    }
};
