<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * До фикса ProductController::store/update marketplace_links сохранялся
     * дважды закодированным JSON (строка внутри строки), потому что модель
     * Product кастует поле как 'array' и сама сериализует значение при
     * сохранении, а контроллер передавал ей уже сериализованную строку.
     * Здесь раскодируем один лишний слой у существующих записей.
     */
    public function up(): void
    {
        $products = DB::table('products')
            ->whereNotNull('marketplace_links')
            ->get(['id', 'marketplace_links']);

        foreach ($products as $product) {
            $decodedOnce = json_decode($product->marketplace_links, true);

            // Если после одного json_decode всё ещё строка — это двойное
            // кодирование, раскодируем второй раз и сохраним нормальный JSON.
            if (is_string($decodedOnce)) {
                $decodedTwice = json_decode($decodedOnce, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['marketplace_links' => json_encode($decodedTwice)]);
                }
            }
        }
    }

    public function down(): void
    {
        // Необратимо: восстановить исходное (баг-совместимое) двойное
        // кодирование не требуется.
    }
};
