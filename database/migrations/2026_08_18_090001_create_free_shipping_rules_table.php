<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила бесплатной доставки (см. docs/tasks/free-shipping.md).
 *
 * Каждое правило — набор условий. Пустое условие (NULL/пустой массив) означает
 * «любое значение» и ограничения не накладывает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);

            // «Сумма бесплатной доставки»: порог сравнивается как >=
            // с суммой выкупа (после скидок, промокода и акций).
            $table->decimal('min_order_amount', 12, 2)->default(0);

            // Мультивыборы с фиксированным набором кодов.
            // services: cdek|yandex, delivery_types: pickup|courier,
            // payment_methods: коды из config('free_shipping.payment_methods').
            $table->json('services')->nullable();
            $table->json('delivery_types')->nullable();
            $table->json('payment_methods')->nullable();

            // Необязательное окно действия правила.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_shipping_rules');
    }
};
