<?php

namespace App\Services\Order;

use App\Models\GiftCard\GiftCard;
use App\Models\Product;

/**
 * Определяет заказ, который ничего не везёт: в нём только электронные подарочные сертификаты.
 *
 * Такому заказу не нужны ни адрес, ни способ доставки: карта уходит покупателю или получателю
 * на почту после оплаты. Пластиковая карта и любой обычный товар в корзине возвращают обычные
 * правила: адрес доставки обязателен.
 */
class DigitalOrderDetector
{
    /** Название товара-сертификата в каталоге (по нему же заказ создаёт карту). */
    public const GIFT_CARD_PRODUCT_NAME = 'Подарочный сертификат';

    /**
     * @param  mixed  $items  позиции заказа как пришли в запросе (product_id, quantity, ...)
     * @param  mixed  $giftCardData  gift_card_data из запроса: тип карты и данные получателя
     */
    public function isElectronicGiftCardOnly(mixed $items, mixed $giftCardData): bool
    {
        if (! is_array($giftCardData) || ($giftCardData['type'] ?? null) !== GiftCard::TYPE_ELECTRONIC) {
            return false;
        }

        if (! is_array($items) || $items === []) {
            return false;
        }

        $ids = [];
        foreach ($items as $item) {
            $productId = is_array($item) ? ($item['product_id'] ?? null) : null;
            if (! is_numeric($productId)) {
                return false;
            }
            $ids[(int) $productId] = true;
        }

        $names = Product::query()->whereIn('id', array_keys($ids))->pluck('name', 'id');

        if ($names->count() !== count($ids)) {
            return false;
        }

        return $names->every(fn ($name) => $name === self::GIFT_CARD_PRODUCT_NAME);
    }
}
