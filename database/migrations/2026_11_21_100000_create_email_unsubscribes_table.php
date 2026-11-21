<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адреса, отписавшиеся от рекламных писем (брошенная корзина, «снова в наличии», промокоды).
 * Письма о заказах (принят, оплачен, отправлен, чек) отписка не отключает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('source', 50)->default('email_link');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_unsubscribes');
    }
};
