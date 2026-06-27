<?php

namespace App\Observers;

use App\Jobs\NotifyRestockSubscribersJob;
use App\Models\Product;
use App\Models\ProductRestockSubscription;

class ProductObserver
{
    /**
     * Триггер «товар появился в наличии онлайн».
     *
     * Условие: переход stock_quantity из 0 в > 0 при is_active = true.
     * Покрывает все пути изменения остатка (синк МойСклад, ручная правка),
     * т.к. при синке остаток товара пересчитывается как сумма остатков вариантов.
     */
    public function updated(Product $product): void
    {
        if (!$product->isDirty('stock_quantity')) {
            return;
        }

        $original = (float)$product->getOriginal('stock_quantity');
        $current = (float)$product->stock_quantity;

        // Переход 0 -> >0 и товар опубликован
        if ($original <= 0 && $current > 0 && $product->is_active) {

            // Есть ли ожидающие подписки на этот товар?
            $hasPending = ProductRestockSubscription::query()
                ->forProduct($product->id)
                ->pending()
                ->exists();

            if ($hasPending) {
                NotifyRestockSubscribersJob::dispatch($product->id);
            }
        }
    }
}
