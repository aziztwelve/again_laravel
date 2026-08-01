<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            $table->unsignedInteger('recovery_cycle')->default(0)->after('recovery_token');
        });

        Schema::table('cart_communications', function (Blueprint $table) {
            $table->unsignedInteger('cycle')->default(1)->after('cart_id');
            $table->dropUnique('cart_communications_cart_step_channel_unique');
            $table->unique(['cart_id', 'cycle', 'step', 'channel']);
        });

        // Уже начатые до миграции цепочки считаем первым циклом, чтобы
        // следующий цикл после новой активности получил номер 2.
        DB::table('cart')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('cart_communications')
                    ->whereColumn('cart_communications.cart_id', 'cart.id');
            })
            ->update(['recovery_cycle' => 1]);
    }

    public function down(): void
    {
        Schema::table('cart_communications', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'cycle', 'step', 'channel']);
            $table->dropColumn('cycle');
            $table->unique(['cart_id', 'step', 'channel']);
        });

        Schema::table('cart', function (Blueprint $table) {
            $table->dropColumn('recovery_cycle');
        });
    }
};
