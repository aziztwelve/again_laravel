<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'starts_at',
        'ends_at',
        'min_purchase_amount',
        'allow_promo_codes',
        'is_stackable',
        'is_active',
        'priority',
        'max_uses',
        'times_used',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'min_purchase_amount' => 'decimal:2',
        'allow_promo_codes' => 'boolean',
        'is_stackable' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'max_uses' => 'integer',
        'times_used' => 'integer',
    ];

    /** Сегменты, которым доступна акция. */
    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Segments\Segment::class, 'promotion_segment')->withTimestamps();
    }

    /** No segment links means public; otherwise membership in any active segment is required. */
    public function scopeAvailableForClient($query, ?int $clientId)
    {
        return $query->where(function ($query) use ($clientId) {
            $query->whereDoesntHave('segments');
            if ($clientId !== null) {
                $query->orWhereHas('segments', function ($segments) use ($clientId) {
                    $segments->where('segments.is_active', true)
                        ->whereHas('clients', fn ($clients) => $clients->where('clients.id', $clientId));
                });
            }
        });
    }

    public function isAvailableForClient(?int $clientId): bool
    {
        return static::whereKey($this->id)->availableForClient($clientId)->exists();
    }

    /** Товары-триггеры (на которые действует акция). */
    public function triggerProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_trigger_products');
    }

    /**
     * Товары-подарки (которые участвуют в акции)
     */
    public function giftProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_gift_products')
            ->withPivot('quantity');
    }

    /**
     * История использования
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /**
     * Заказы, использующие эту акцию
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Проверка: активна ли акция
     */
    public function isActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->max_uses && $this->times_used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Проверка: можно ли использовать промокоды с этой акцией
     */
    public function allowsPromoCodes(): bool
    {
        return $this->allow_promo_codes;
    }

    /**
     * Проверка: складывается ли акция с другими стекируемыми акциями
     */
    public function isStackable(): bool
    {
        return (bool) $this->is_stackable;
    }

    /**
     * Получить статус акции
     */
    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return 'scheduled';
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return 'expired';
        }

        if ($this->max_uses && $this->times_used >= $this->max_uses) {
            return 'exhausted';
        }

        return 'active';
    }
}
