<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * ID товаров, которые до сих пор показывались в статичном блоке главной
     * (Home/Catalog.vue: per_page=12 + slice(4), т.е. позиции 5-12 по
     * display_order среди активных товаров в наличии). Переносим их в ручную
     * подборку категории "Новинки 8", по аналогии с категорией "novinki".
     */
    private const PRODUCT_IDS = [357, 355, 246, 235, 238, 348, 358, 337];

    public function up(): void
    {
        $category = Category::where('slug', 'novinki-8')->first();

        if (!$category) {
            $category = new Category();
            $category->name = 'Новинки 8';
            $category->show_in_catalog_menu = false;
            $category->show_as_home_banner = false;
            $category->is_new_product = false;
            $category->is_coming_soon = false;
            $category->menu_order = 0;
            $category->save();
        } else {
            $category->is_new_product = false;
            $category->save();
        }

        foreach (self::PRODUCT_IDS as $index => $productId) {
            DB::table('category_product')->updateOrInsert(
                ['category_id' => $category->id, 'product_id' => $productId],
                ['position' => $index + 1],
            );
        }
    }

    public function down(): void
    {
        $category = Category::where('slug', 'novinki-8')->first();

        if (!$category) {
            return;
        }

        DB::table('category_product')->where('category_id', $category->id)->delete();
        $category->delete();
    }
};
