<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('order_page_opened_at')->nullable()->index();
            $table->timestamp('payment_interface_started_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_page_opened_at']);
            $table->dropIndex(['reservation_expires_at']);
            $table->dropColumn(['order_page_opened_at', 'payment_interface_started_at', 'reservation_expires_at']);
        });
    }
};
