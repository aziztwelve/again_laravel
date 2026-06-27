<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRestockSubscription extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_NOTIFIED = 'notified';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'client_id',
        'name',
        'email',
        'phone',
        'status',
        'notified_at',
        'source',
        'meta',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
        'notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Scope: только ожидающие поступления подписки.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: подписки на конкретный товар.
     */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
