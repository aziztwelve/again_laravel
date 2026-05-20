<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Делаем promo_code_usages.client_id nullable, чтобы можно было
 * записывать использование публичного промокода в гостевом заказе
 * (client_id = NULL). Для зарегистрированных клиентов поведение
 * не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Снимаем FK только если он реально существует (на части окружений
        // он мог быть уже удалён более ранней миграцией). Иначе ALTER упадёт.
        if ($this->foreignKeyExists('promo_code_usages', 'promo_code_usages_client_id_foreign')) {
            Schema::table('promo_code_usages', function (Blueprint $table) {
                $table->dropForeign(['client_id']);
            });
        }

        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        // FK возвращаем заново, чтобы сохранить целостность данных.
        if (! $this->foreignKeyExists('promo_code_usages', 'promo_code_usages_client_id_foreign')) {
            Schema::table('promo_code_usages', function (Blueprint $table) {
                $table->foreign('client_id')
                    ->references('id')
                    ->on('clients')
                    ->cascadeOnDelete();
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $database = \DB::getDatabaseName();
        $row = \DB::selectOne(
            'SELECT COUNT(*) AS cnt
               FROM information_schema.table_constraints
              WHERE table_schema = ?
                AND table_name = ?
                AND constraint_name = ?
                AND constraint_type = \'FOREIGN KEY\'',
            [$database, $table, $constraintName]
        );

        return (int) ($row->cnt ?? 0) > 0;
    }

    public function down(): void
    {
        // Перед возвратом к NOT NULL чистим NULL-записи (гостевые),
        // иначе ALTER не пройдёт.
        \DB::table('promo_code_usages')->whereNull('client_id')->delete();

        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });

        Schema::table('promo_code_usages', function (Blueprint $table) {
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->cascadeOnDelete();
        });
    }
};
