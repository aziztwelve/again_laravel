<?php

use AppServices\Delivery\CdekDeliveryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('delivery_methods')->updateOrInsert(
            ['code' => 'cdek_postamat'],
            [
                'name' => 'Постамат СДЭК',
                'description' => 'Получение заказа в постамате СДЭК',
                'provider_class' => CdekDeliveryService::class,
                'settings' => json_encode(['kind' => 'postamat', 'company' => 'cdek'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('delivery_methods')->where('code', 'cdek_postamat')->delete();
    }
};
