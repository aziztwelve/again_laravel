<?php

namespace App\Http\Controllers\Api\Public\Cart;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Traits\ProductsTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичный пересчёт цен товаров в корзине.
 *
 * Зачем нужен:
 *  - корзина у нас живёт в localStorage и не синхронизируется с сервером
 *    (см. nuxt-shop/stores/cart.js).
 *  - У пользователя в localStorage может висеть «старая» цена товара —
 *    например, потому что он положил товар в корзину ДО активации/изменения
 *    скидки. На чекауте бэк считает актуальную цену с учётом скидок и
 *    бракует заказ с PRICE_MISMATCH.
 *  - Этот endpoint позволяет фронту перед оформлением (или при заходе
 *    на /checkout) забрать актуальные цены/скидки по тем же позициям и
 *    обновить localStorage, чтобы юзер увидел реальную цену ДО клика
 *    «Оформить заказ».
 *
 * Эндпоинт публичный: работает и для гостя, и для авторизованного клиента
 * (auth тут не нужен — мы просто читаем публичные цены товаров).
 */
class CartPriceController extends Controller
{
    use ProductsTrait;

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
        ]);

        $items = [];

        foreach ($validated['items'] as $item) {
            $productId = (int) $item['product_id'];
            $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;

            $model = null;
            if ($variantId) {
                $model = ProductVariant::query()
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('id', $variantId)
                    ->where('product_id', $productId)
                    ->first();
            }

            if (! $model) {
                $model = Product::query()
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('id', $productId)
                    ->first();
            }

            if (! $model) {
                // Товара уже нет — фронт может удалить его из корзины,
                // но пусть это делает он сам, чтобы юзер увидел сообщение.
                $items[] = [
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'available' => false,
                ];

                continue;
            }

            $originalPrice = (float) $model->price;

            // applyDiscountToProduct мутирует $model->price → актуальная цена со скидкой,
            // и выставляет old_price/discount_id/discount_percentage/total_discount.
            $this->applyDiscountToProduct($model);

            $items[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'available' => true,
                'price' => (float) $model->price,
                'old_price' => $model->old_price !== null ? (float) $model->old_price : null,
                'original_price' => $originalPrice,
                'discount_id' => $model->discount_id,
                'discount_percentage' => $model->discount_percentage !== null
                    ? (float) $model->discount_percentage
                    : null,
                'total_discount' => $model->total_discount !== null
                    ? (float) $model->total_discount
                    : null,
                'stock_quantity' => (float) ($model->stock_quantity ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }
}
