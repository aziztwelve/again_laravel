<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->uuid('checkout_idempotency_key')
                    ->nullable()
                    ->unique('orders_checkout_idempotency_key_unique')
                    ->after('view_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_checkout_idempotency_key_unique');
                $table->dropColumn('checkout_idempotency_key');
            });
        }
    }
};
