<?php

namespace App\Http\Controllers\Api\Public\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Traits\ProductsTrait;
use Illuminate\Http\JsonResponse;

/**
 * Публичное восстановление брошенной корзины по recovery_token из письма.
 *
 * Витрина (nuxt-shop) открывает {FRONTEND_URL}/cart/restore/{token}, дёргает
 * этот endpoint, подставляет позиции в localStorage (с актуальными ценами) и
 * ведёт пользователя на /checkout. См. docs/tasks/abandoned-cart.md.
 *
 * Только товары, доступные сейчас (активные, не удалённые). Цены — актуальные,
 * со скидками (applyDiscountToProduct), чтобы не словить PRICE_MISMATCH на чекауте.
 */
class CartRestoreController extends Controller
{
    use ProductsTrait;

    public function show(string $token): JsonResponse
    {
        $cart = Cart::with(['items'])
            ->where('recovery_token', $token)
            ->first();

        if (! $cart) {
            return response()->json([
                'success' => false,
                'message' => 'Корзина не найдена или ссылка устарела.',
            ], 404);
        }

        // Открытие ссылки восстановления = активность пользователя: возвращаем
        // брошенную корзину в active и фиксируем last_activity_at (lifecycle
        // ABANDONED → ACTIVE). Оформленную (ordered) корзину не трогаем.
        // См. docs/tasks/universal-cart.md.
        $revive = ['last_activity_at' => now()];
        if ($cart->status === 'abandoned') {
            $revive['status'] = 'active';
        }
        $cart->forceFill($revive)->save();

        $items = [];

        foreach ($cart->items as $item) {
            if (! is_null($item->product_variant_id)) {
                $model = ProductVariant::with(['images' => fn ($q) => $q->orderBy('order', 'asc')])
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('id', $item->product_variant_id)
                    ->where('product_id', $item->product_id)
                    ->first();
            } else {
                $model = Product::with(['images' => fn ($q) => $q->orderBy('order', 'asc')])
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('id', $item->product_id)
                    ->first();
            }

            if (! $model) {
                continue; // товар больше недоступен — пропускаем
            }

            $this->applyDiscountToProduct($model);

            $items[] = [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'color_id' => $item->color_id,
                'qty' => (int) $item->quantity,
                'name' => $model->name,
                'slug' => $model->slug,
                'price' => $model->price,
                'old_price' => $model->old_price,
                'discount_percentage' => $model->discount_percentage,
                'total_discount' => $model->total_discount,
                'currency' => $model->currency,
                'images' => ImageResource::collection($model->images),
            ];
        }

        return response()->json([
            'success' => true,
            'cart_id' => $cart->id,
            'items' => $items,
        ]);
    }
}
