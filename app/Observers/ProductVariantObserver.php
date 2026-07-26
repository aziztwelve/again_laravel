<?php

namespace App\Observers;

use App\Jobs\NotifyRestockSubscribersJob;
use App\Models\ProductRestockSubscription;
use App\Models\ProductVariant;

class ProductVariantObserver
{
    /** Запускает проверку ожидающих заявок при поступлении конкретного цвета. */
    public function updated(ProductVariant $variant): void
    {
        if (! $variant->isDirty('stock_quantity') || ! $variant->color_id) {
            return;
        }

        if ((float) $variant->getOriginal('stock_quantity') > 0 || (float) $variant->stock_quantity <= 0) {
            return;
        }

        $product = $variant->product;
        if (! $product?->is_active) {
            return;
        }

        if (ProductRestockSubscription::query()->forProduct($variant->product_id)->pending()->exists()) {
            NotifyRestockSubscribersJob::dispatch($variant->product_id);
        }
    }
}
