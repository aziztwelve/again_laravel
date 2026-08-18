<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочники географии `country` / `region` / `city` исторически заводились
 * вручную (импортом), миграции для них не было. Из-за этого схема не
 * воспроизводится в чистом окружении: на тестовой БД падало всё, что читает
 * страны/регионы (в т.ч. правила бесплатной доставки — docs/tasks/free-shipping.md).
 *
 * Миграция создаёт таблицы ТОЛЬКО если их нет: на боевой/dev БД, где они уже
 * заполнены, ничего не делает. Схема повторяет фактическую:
 *  - id — ПОДПИСАННЫЙ bigint auto_increment (есть запись с id = 0 — Россия);
 *  - crt_date вместо timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('country')) {
            Schema::create('country', function (Blueprint $table) {
                $table->bigInteger('id', true);
                $table->string('name', 128);
                $table->string('code', 10)->nullable();
                $table->string('phone_code', 10)->nullable();
                $table->integer('phone_length')->nullable();
                $table->dateTime('crt_date')->useCurrent();
            });
        }

        if (! Schema::hasTable('region')) {
            Schema::create('region', function (Blueprint $table) {
                $table->bigInteger('id', true);
                $table->bigInteger('country_id');
                $table->string('name', 128);
                $table->dateTime('crt_date')->useCurrent();

                $table->index('country_id');
            });
        }

        if (! Schema::hasTable('city')) {
            Schema::create('city', function (Blueprint $table) {
                $table->bigInteger('id', true);
                $table->bigInteger('region_id');
                $table->string('name', 128);
                $table->dateTime('crt_date')->useCurrent();

                $table->index('region_id');
            });
        }
    }

    public function down(): void
    {
        // Намеренно ничего не удаляем: таблицы могли существовать до миграции
        // и содержать боевые данные.
    }
};
