<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ручная отправка напоминания (см. docs/tasks/abandoned-cart.md, шаг F).
 *
 * Делаем cart_communications.step nullable: триггерные шаги (1, 2) сохраняют
 * UNIQUE(cart_id, step) для идемпотентности, а ручные отправки (type='manual')
 * пишутся со step=NULL — MySQL считает NULL различными в unique-индексе, поэтому
 * менеджер может слать вручную несколько раз без конфликта.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cart_communications MODIFY step TINYINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Возврат к NOT NULL: ручные (NULL) строки переводим в 0, чтобы не упасть.
        DB::statement('UPDATE cart_communications SET step = 0 WHERE step IS NULL');
        DB::statement('ALTER TABLE cart_communications MODIFY step TINYINT UNSIGNED NOT NULL');
    }
};
