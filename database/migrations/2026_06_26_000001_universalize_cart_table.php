<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Универсальная серверная корзина — Фаза 1 (см. docs/tasks/universal-cart.md).
 *
 * - Новые поля идентичности гостя и контактов/согласия: guest_token (UUID,
 *   UNIQUE), email, phone, marketing_consent, consent_at, last_activity_at,
 *   device_hash, user_agent, ip_address.
 * - Явный статус 'active' вместо NULL: enum('active','abandoned','ordered'),
 *   NOT NULL DEFAULT 'active'; существующие NULL бэкфиллятся в 'active'.
 * - CHECK-constraint «ровно одна личность» (MySQL 8.0): client_id XOR guest_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart', function (Blueprint $table) {
            if (! Schema::hasColumn('cart', 'guest_token')) {
                $table->char('guest_token', 36)->nullable()->unique()->after('client_id');
            }
            if (! Schema::hasColumn('cart', 'email')) {
                $table->string('email')->nullable()->after('guest_token');
            }
            if (! Schema::hasColumn('cart', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('cart', 'marketing_consent')) {
                $table->boolean('marketing_consent')->default(false)->after('phone');
            }
            if (! Schema::hasColumn('cart', 'consent_at')) {
                $table->timestamp('consent_at')->nullable()->after('marketing_consent');
            }
            if (! Schema::hasColumn('cart', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('updated_at');
            }
            if (! Schema::hasColumn('cart', 'device_hash')) {
                $table->string('device_hash')->nullable();
            }
            if (! Schema::hasColumn('cart', 'user_agent')) {
                $table->string('user_agent')->nullable();
            }
            if (! Schema::hasColumn('cart', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
        });

        // Статус: добавить 'active', бэкфилл NULL → 'active', NOT NULL DEFAULT.
        DB::statement("ALTER TABLE cart MODIFY status ENUM('active','abandoned','ordered') NULL");
        DB::statement("UPDATE cart SET status = 'active' WHERE status IS NULL");
        DB::statement("ALTER TABLE cart MODIFY status ENUM('active','abandoned','ordered') NOT NULL DEFAULT 'active'");

        // last_activity_at для существующих строк = последняя известная активность.
        DB::statement('UPDATE cart SET last_activity_at = COALESCE(updated_at, created_at) WHERE last_activity_at IS NULL');

        // Орфанные корзины без личности (client_id IS NULL) — назначить guest_token.
        // Поддерживает инвариант «ровно одна личность» для существующих данных.
        // UUID() в MySQL вычисляется построчно, что даёт уникальные значения.
        DB::statement('UPDATE cart SET guest_token = (UUID()) WHERE client_id IS NULL AND guest_token IS NULL');

        // ПРИМЕЧАНИЕ: DB-level CHECK «ровно одна личность» (client_id XOR guest_token)
        // в MySQL 8.0 невозможен — колонка client_id участвует в FK fk_user_id с
        // ON DELETE SET NULL, а MySQL запрещает использовать такие колонки в CHECK
        // (ошибка 3823). Инвариант обеспечивается на уровне приложения в CartResolver
        // (Фаза 2): корзина всегда создаётся либо с client_id, либо с guest_token;
        // при логине/мердже guest_token обнуляется. Отдельно в Фазе 2 решить
        // поведение при удалении клиента (ON DELETE SET NULL оставит корзину без
        // личности) — назначать guest_token или удалять корзину.
    }

    public function down(): void
    {
        // Вернуть статус к nullable enum без 'active'.
        DB::statement("UPDATE cart SET status = NULL WHERE status = 'active'");
        DB::statement("ALTER TABLE cart MODIFY status ENUM('abandoned','ordered') NULL");

        Schema::table('cart', function (Blueprint $table) {
            if (Schema::hasColumn('cart', 'guest_token')) {
                $table->dropUnique(['guest_token']);
            }
            foreach ([
                'guest_token', 'email', 'phone', 'marketing_consent', 'consent_at',
                'last_activity_at', 'device_hash', 'user_agent', 'ip_address',
            ] as $column) {
                if (Schema::hasColumn('cart', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
