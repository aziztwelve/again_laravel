<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_restock_subscriptions', function (Blueprint $table) {
            $table->json('color_ids')->nullable()->after('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_restock_subscriptions', function (Blueprint $table) {
            $table->dropColumn('color_ids');
        });
    }
};
