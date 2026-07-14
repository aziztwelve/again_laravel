<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_communications', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'step']);
            $table->unique(['cart_id', 'step', 'channel'], 'cart_communications_cart_step_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cart_communications', function (Blueprint $table) {
            $table->dropUnique('cart_communications_cart_step_channel_unique');
            $table->unique(['cart_id', 'step']);
        });
    }
};
