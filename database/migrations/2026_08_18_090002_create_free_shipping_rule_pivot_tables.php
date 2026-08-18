<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мультивыборы правил бесплатной доставки, требующие ссылок на справочники:
 * товары, страны и регионы (см. docs/tasks/free-shipping.md).
 *
 * ВАЖНО: legacy-таблицы `country` / `region` используют ПОДПИСАННЫЙ bigint
 * (`bigint NOT NULL`, а не `bigint unsigned`) и содержат запись с id = 0
 * (Россия / «Москва и Московская обл.»). Поэтому колонки объявляем
 * `bigInteger` (signed) и не навешиваем на них FK — справочники статические,
 * а несовпадение знаковости ломает создание внешнего ключа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_shipping_rule_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('free_shipping_rule_id')
                ->constrained('free_shipping_rules')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['free_shipping_rule_id', 'product_id'], 'fsr_products_unique');
        });

        Schema::create('free_shipping_rule_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('free_shipping_rule_id')
                ->constrained('free_shipping_rules')
                ->cascadeOnDelete();
            $table->bigInteger('country_id');
            $table->timestamps();

            $table->unique(['free_shipping_rule_id', 'country_id'], 'fsr_countries_unique');
            $table->index('country_id');
        });

        Schema::create('free_shipping_rule_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('free_shipping_rule_id')
                ->constrained('free_shipping_rules')
                ->cascadeOnDelete();
            $table->bigInteger('region_id');
            $table->timestamps();

            $table->unique(['free_shipping_rule_id', 'region_id'], 'fsr_regions_unique');
            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_shipping_rule_regions');
        Schema::dropIfExists('free_shipping_rule_countries');
        Schema::dropIfExists('free_shipping_rule_products');
    }
};
