<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CartDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $client = $this->client;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_activity_at' => $this->last_activity_at,
            'abandoned_at' => $this->abandoned_at,
            'ordered_at' => $this->ordered_at,
            'linked_order' => $this->order ? ['id' => $this->order->id, 'number' => $this->order->order_number] : null,
            'total' => (float) $this->total,
            'total_original' => (float) $this->total_original,
            'total_discount' => (float) $this->total_discount,
            'customer' => [
                'id' => $client?->id,
                'name' => $client?->profile?->full_name,
                'first_name' => $client?->profile?->first_name,
                'last_name' => $client?->profile?->last_name,
                'middle_name' => $client?->profile?->middle_name,
                'phone' => $client?->profile?->phone,
                'email' => $client?->email,
                'country' => $client?->profile?->country?->name,
                'city' => $client?->profile?->city?->name,
                'region' => $client?->profile?->delivery_region,
            ],
            'items' => $this->items->map(function (CartItem $item) {
                $product = $item->product;
                $variant = $item->productVariant;
                $model = $item->product_variant_id ? $variant : $product;
                $image = $variant?->images->first() ?? $product?->images->first();
                $available = $product && ! $product->trashed() && $product->is_active
                    && $model && ! $model->trashed() && $model->is_active
                    && (! $variant || (int) $variant->product_id === (int) $product->id);

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'color_id' => $item->color_id ?? $variant?->color_id,
                    'name' => $product?->name ?? 'Удалённый товар',
                    'variant_name' => $variant?->name,
                    'sku' => $variant?->sku ?? $product?->sku,
                    'color' => $item->color?->name,
                    'image_url' => $image?->path ? url(Storage::url($image->path)) : null,
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->price,
                    'price_original' => (float) $item->price_original,
                    'total' => (float) $item->total,
                    'total_discount' => (float) $item->total_discount,
                    'available' => (bool) $available,
                    'stock_quantity' => $available ? (float) ($model->stock_quantity ?? 0) : 0,
                ];
            })->values(),
            'communications' => $this->communications->map(fn ($communication) => [
                'id' => $communication->id,
                'channel' => $communication->channel,
                'type' => $communication->type,
                'step' => $communication->step,
                'status' => $communication->status,
                'subject' => $communication->subject,
                'body' => $communication->body,
                'html' => $communication->html,
                'created_at' => $communication->created_at,
                'sent_at' => $communication->sent_at,
            ])->values(),
            // Все заказы клиента — контекст; только cart_id доказывает конверсию этой корзины.
            'orders' => $client?->orders->map(fn ($order) => [
                'id' => $order->id,
                'number' => $order->order_number,
                'created_at' => $order->created_at,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'total' => (float) $order->total_amount,
                'from_this_cart' => (int) $order->cart_id === (int) $this->id,
            ])->values() ?? [],
            'orders_count' => (int) ($client?->orders_count ?? 0),
            'can_create_order' => $client && in_array($this->status, ['active', 'abandoned'], true)
                && $this->items->isNotEmpty(),
        ];
    }
}
