<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'price',
        'discount',
        'color_id',
        'is_gift',
        'promotion_id',
        'legacy_name',
        'legacy_sku',
        'purchase_price',
        'marking_codes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'is_gift' => 'boolean',
        'purchase_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function color()
    {
        return $this->hasOne(Color::class, 'id', 'color_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Что отправлять в МойСклад как номенклатуру позиции: модификация (variant),
     * а если у позиции нет варианта (простой товар без модификаций) — сам товар.
     *
     * @return array{type: string, uuid: string}|null
     */
    public function moyskladAssortment(): ?array
    {
        if ($uuid = $this->variant?->uuid) {
            return ['type' => 'variant', 'uuid' => $uuid];
        }

        if ($this->product_variant_id === null && ($uuid = $this->product?->uuid)) {
            return ['type' => 'product', 'uuid' => $uuid];
        }

        return null;
    }
}
