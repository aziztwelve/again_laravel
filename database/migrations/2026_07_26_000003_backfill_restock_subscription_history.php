<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_restock_subscriptions')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($subscriptions) {
                $rows = [];

                foreach ($subscriptions as $subscription) {
                    $exists = DB::table('product_restock_subscription_histories')
                        ->where('product_restock_subscription_id', $subscription->id)
                        ->where('action', 'created')
                        ->exists();

                    if (! $exists) {
                        $rows[] = [
                            'product_restock_subscription_id' => $subscription->id,
                            'action' => 'created',
                            'description' => 'Заявка создана на сайте',
                            'created_at' => $subscription->created_at,
                            'updated_at' => $subscription->created_at,
                        ];
                    }
                }

                if ($rows) {
                    DB::table('product_restock_subscription_histories')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('product_restock_subscription_histories')
            ->where('action', 'created')
            ->where('description', 'Заявка создана на сайте')
            ->delete();
    }
};
