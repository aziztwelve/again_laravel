<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Отдельное право на удаление заказов (orders.delete).
 *
 * Раньше удалить заказ мог любой сотрудник (проверялось только «не клиент»).
 * Теперь право выдано роли «Супер Администратор»; остальным его можно выдать
 * точечно через «Пользователи → права» (user_permissions).
 */
return new class extends Migration
{
    private const SLUG = 'orders.delete';

    private const SUPER_ADMIN_ROLE_SLUG = 'super-admin';

    public function up(): void
    {
        $now = now();

        $permission = DB::table('permissions')->where('slug', self::SLUG)->first();
        $permissionId = $permission?->id ?? DB::table('permissions')->insertGetId([
            'name' => 'Удаление заказов',
            'slug' => self::SLUG,
            'description' => 'Право удалять заказы. По умолчанию только у супер-администратора.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', self::SUPER_ADMIN_ROLE_SLUG)->value('id');
        if ($roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
