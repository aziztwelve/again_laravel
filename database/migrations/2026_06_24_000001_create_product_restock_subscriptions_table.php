<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_restock_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Информативно: какой вариант смотрел клиент. Триггер — на уровне товара.
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            // Если клиент авторизован — для отправки в Telegram/VK по привязке профиля.
            $table->foreignId('client_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('email');               // обязателен — основной канал
            $table->string('phone')->nullable();    // доп. канал — WhatsApp

            // pending — ждёт поступления, notified — уведомлён (терминальный).
            $table->string('status')->default('pending');
            $table->timestamp('notified_at')->nullable();

            $table->string('source')->nullable();   // 'site' и т.п. — для аналитики
            $table->json('meta')->nullable();        // utm/доп. данные
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_restock_subscriptions');
    }
};
