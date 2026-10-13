<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Customer-facing history: expose receipt data, never internal order notes. */
class OrderHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items->map(function ($item) {
            $images = $item->variant?->images;
            $image = $images?->firstWhere('is_main', true) ?? $images?->first();
            $images = $item->product?->images;
            $image ??= $images?->firstWhere('is_main', true) ?? $images?->first();
            $quantity = (int) $item->quantity;
            $price = (float) $item->price;
            $discount = (float) $item->discount;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'color_id' => $item->color_id,
                'name' => $item->product?->name ?? $item->legacy_name ?? 'Товар недоступен',
                'product_slug' => $item->product?->slug,
                'variant_name' => $item->variant?->name,
                'color_name' => $item->color?->name,
                'quantity' => $quantity,
                'total' => round($price * $quantity, 2),
                'original_total' => round(($price + $discount) * $quantity, 2),
                'discount_total' => round($discount * $quantity, 2),
                'is_gift' => (bool) $item->is_gift,
                'image_path' => $image?->path,
            ];
        })->values();

        $address = $this->delivery_address;
        $addressParts = is_array($address) ? array_filter([
            $address['country'] ?? null,
            $address['region'] ?? null,
            $address['city'] ?? null,
            $address['address'] ?? null,
        ]) : [];
        $addressText = implode(', ', array_unique($addressParts));
        if (is_array($address) && filled($address['entrance'] ?? null)) {
            $addressText .= ($addressText !== '' ? ', ' : '').'кв. '.$address['entrance'];
        }

        return [
            'id' => $this->id,
            'order_number' => $this->order_number ?? $this->id,
            'view_token' => $this->view_token,
            'created_at' => $this->created_at?->toIso8601String(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => $items->all(),
            'quantity' => $items->sum('quantity'),
            'subtotal' => round($items->sum('original_total'), 2),
            // Item discounts already include the promo discount; do not add it twice.
            'discount_amount' => (float) $this->discount_amount,
            'delivery_cost' => $this->delivery_cost === null ? null : (float) $this->delivery_cost,
            'total_amount' => (float) $this->total_amount,
            'gift_card_amount' => (float) $this->gift_card_amount,
            'delivery_address' => $addressText ?: null,
            'delivery_method' => $this->deliveryMethod?->name ?? $this->legacy_delivery_method,
            'delivery_target' => $this->deliveryTarget?->name,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->yandexOrder?->tracking_url ?? $this->cdekOrder?->tracking_url,
        ];
    }
}
