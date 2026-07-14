<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Внутренний комментарий продавца/менеджера к заказу.
            // Не путать с notes (комментарий покупателя).
            $table->text('seller_comment')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('seller_comment');
        });
    }
};
