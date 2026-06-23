<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поддержка применения неограниченного количества ручных скидок к одному заказу.
 *
 * Раньше: orders.applied_discount_id — одна ручная скидка, новая заменяла предыдущую.
 * Теперь: pivot order_applied_discounts(order_id, discount_id, applied_amount, position)
 * — все ручные скидки стекаются поверх авто-скидок (Product↔Discount); промокод
 * продолжает применяться поверх по своему discount_behavior (stack/replace/skip).
 *
 * applied_amount хранится для удобного отображения «Скидка X: −500 ₽» в UI/истории.
 * Источник истины для цен по-прежнему order_items.price/discount.
 *
 * Колонку orders.applied_discount_id оставляем для обратной совместимости (deprecated).
 * Существующие значения переносим в pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_applied_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'discount_id']);
            $table->index(['order_id', 'position']);
        });

        // Backfill: переносим существующие orders.applied_discount_id в pivot
        DB::table('orders')
            ->whereNotNull('applied_discount_id')
            ->orderBy('id')
            ->select(['id', 'applied_discount_id', 'total_items_discount', 'created_at', 'updated_at'])
            ->chunkById(500, function ($rows) {
                $insert = [];
                $now = now();
                foreach ($rows as $row) {
                    $insert[] = [
                        'order_id'       => $row->id,
                        'discount_id'    => $row->applied_discount_id,
                        'applied_amount' => (float) ($row->total_items_discount ?? 0),
                        'position'       => 0,
                        'created_at'     => $row->created_at ?? $now,
                        'updated_at'     => $row->updated_at ?? $now,
                    ];
                }
                if ($insert) {
                    DB::table('order_applied_discounts')->insertOrIgnore($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_applied_discounts');
    }
};
