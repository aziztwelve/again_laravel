<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'email')) {
                // Email покупателя (используется для гостевых заказов, когда
                // нет клиента в clients и, соответственно, нет client.email).
                // Для авторизованных клиентов это поле обычно остаётся пустым —
                // email там берётся из связанного клиента.
                $table->string('email')->nullable()->after('notes');
                $table->index('email', 'orders_email_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'email')) {
                $table->dropIndex('orders_email_index');
                $table->dropColumn('email');
            }
        });
    }
};
