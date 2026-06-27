<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Support\Facades\DB;

/**
 * Слияние/миграция гостевой корзины в клиентскую при логине/регистрации
 * (см. docs/tasks/universal-cart.md).
 */
class CartMerger
{
    /**
     * Привязать гостевую корзину (по guest_token) к клиенту.
     *
     * Сценарии:
     *  - у клиента нет активной корзины → миграция: гостевая становится
     *    клиентской (client_id выставляется, guest_token обнуляется);
     *  - у клиента есть активная корзина → мердж позиций (суммирование
     *    количеств с дедупом), гостевая корзина удаляется.
     *
     * @return Cart|null Итоговая активная корзина клиента (или null, если
     *                   ни гостевой, ни клиентской корзины нет).
     */
    public function attachGuestCartToClient(int $clientId, ?string $guestToken): ?Cart
    {
        $clientCart = Cart::where('client_id', $clientId)
            ->where('status', 'active')
            ->first();

        if (! $guestToken) {
            return $clientCart;
        }

        $guestCart = Cart::where('guest_token', $guestToken)
            ->where('status', 'active')
            ->with('items')
            ->first();

        if (! $guestCart) {
            return $clientCart;
        }

        // Миграция: у клиента ещё нет активной корзины — переназначаем гостевую.
        if (! $clientCart) {
            $guestCart->forceFill([
                'client_id' => $clientId,
                'guest_token' => null,
                'updated_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            return $guestCart->fresh('items');
        }

        // Мердж: сливаем позиции гостевой в клиентскую, гостевую удаляем.
        return DB::transaction(function () use ($clientCart, $guestCart) {
            foreach ($guestCart->items as $guestItem) {
                $existing = $clientCart->items()
                    ->where('product_id', $guestItem->product_id)
                    ->where(function ($q) use ($guestItem) {
                        is_null($guestItem->product_variant_id)
                            ? $q->whereNull('product_variant_id')
                            : $q->where('product_variant_id', $guestItem->product_variant_id);
                    })
                    ->first();

                if ($existing) {
                    $this->mergeQuantities($existing, $guestItem);
                } else {
                    // Переносим позицию в клиентскую корзину.
                    $guestItem->forceFill(['cart_id' => $clientCart->id])->save();
                }
            }

            // Удаляем гостевую корзину вместе с оставшимися (продублированными) позициями.
            $guestCart->items()->delete();
            $guestCart->delete();

            $this->recalculateTotals($clientCart);

            return $clientCart->fresh('items');
        });
    }

    /**
     * Сложить количества двух одинаковых позиций; пересчитать line-итоги.
     * Цену берём из уже существующей (клиентской) позиции.
     */
    protected function mergeQuantities(CartItem $existing, CartItem $guestItem): void
    {
        $newQty = (int) $existing->quantity + (int) $guestItem->quantity;

        $price = (float) $existing->price;
        $priceOriginal = (float) ($existing->price_original ?? $existing->price);
        $unitDiscount = max($priceOriginal - $price, 0);

        $existing->forceFill([
            'quantity' => $newQty,
            'total' => $price * $newQty,
            'total_original' => $priceOriginal * $newQty,
            'total_discount' => $unitDiscount * $newQty,
        ])->save();
    }

    /**
     * Пересчитать итоги корзины по её позициям.
     */
    protected function recalculateTotals(Cart $cart): void
    {
        $cart->forceFill([
            'total' => $cart->items()->sum('total'),
            'total_original' => $cart->items()->sum('total_original'),
            'total_discount' => $cart->items()->sum('total_discount'),
            'updated_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }
}
