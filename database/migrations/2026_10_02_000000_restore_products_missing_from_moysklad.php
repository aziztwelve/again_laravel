<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restore products hidden by the previous MoySklad synchronisation.
     *
     * A missing MoySklad item is now represented as an active catalog item
     * with zero stock, so it can be shown in the "Скоро в продаже" section.
     */
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('uuid')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($products): void {
                $productIds = $products->pluck('id');
                $now = now();

                DB::table('products')
                    ->whereIn('id', $productIds)
                    ->update([
                        'stock_quantity' => 0,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);

                DB::table('product_variants')
                    ->whereIn('product_id', $productIds)
                    ->whereNull('deleted_at')
                    ->update([
                        'stock_quantity' => 0,
                        'updated_at' => $now,
                    ]);
            });
    }

    public function down(): void
    {
        // Deliberately irreversible: restoring a catalog item is safe, while
        // deleting it again would hide a product from customers.
    }
};
