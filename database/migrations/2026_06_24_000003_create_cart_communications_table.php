<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал коммуникаций по брошенным корзинам (фича «Брошенная корзина»,
     * см. docs/tasks/abandoned-cart.md). Наполняет колонки «Канал /
     * Коммуникация / Тип коммуникации» в таблице админки и обеспечивает
     * идемпотентность цепочки (один шаг — максимум одна отправка на корзину).
     */
    public function up(): void
    {
        Schema::create('cart_communications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')
                ->constrained('cart')
                ->cascadeOnDelete();

            // Канал из NotificationService: email / telegram / whatsapp / vk.
            $table->string('channel');

            // Номер шага цепочки: 1 = 24ч, 2 = 72ч.
            $table->unsignedTinyInteger('step');

            // Тип коммуникации: trigger («По триггеру»). На будущее — manual.
            $table->string('type')->default('trigger');

            // queued / sent / failed.
            $table->string('status')->default('queued');

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Идемпотентность: один шаг — одна запись на корзину.
            $table->unique(['cart_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_communications');
    }
};
