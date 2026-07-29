<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('category_product', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('product_id');
            $table->index(['category_id', 'position']);
        });

        DB::table('category_product')->orderBy('category_id')->orderBy('product_id')->get()
            ->groupBy('category_id')
            ->each(function ($products) {
                foreach ($products->values() as $index => $product) {
                    DB::table('category_product')
                        ->where('category_id', $product->category_id)
                        ->where('product_id', $product->product_id)
                        ->update(['position' => $index + 1]);
                }
            });

        $newCategory = Category::where('slug', 'novinki')->first();
        if (!$newCategory) {
            return;
        }

        $preferredIds = [350, 352, 351, 353];
        $otherIds = Product::query()->where('is_new', true)->whereNotIn('id', $preferredIds)
            ->orderBy('display_order')->orderBy('id')->pluck('id')->all();
        $productIds = array_values(array_unique([...$preferredIds, ...$otherIds]));

        foreach ($productIds as $index => $productId) {
            DB::table('category_product')->updateOrInsert(
                ['category_id' => $newCategory->id, 'product_id' => $productId],
                ['position' => $index + 1],
            );
        }

        $newCategory->update(['is_new_product' => false]);
    }

    public function down(): void
    {
        Schema::table('category_product', function (Blueprint $table) {
            $table->dropIndex(['category_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
