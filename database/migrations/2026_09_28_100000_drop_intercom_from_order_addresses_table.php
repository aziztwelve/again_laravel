<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поле «Домофон» убрано из чекаута и дашборда. Заполненных значений на
 * момент удаления — 13 из 37314 адресов, данные колонки не переносятся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            $table->dropColumn('intercom');
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            $table->string('intercom')->nullable();
        });
    }
};
