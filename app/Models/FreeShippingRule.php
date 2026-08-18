<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило бесплатной доставки (см. docs/tasks/free-shipping.md).
 *
 * Набор условий; каждое пустое условие означает «любое значение».
 * Правило срабатывает, только если совпали ВСЕ заполненные условия и сумма
 * выкупа (после скидок, промокода и акций) >= min_order_amount.
 */
class FreeShippingRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'is_active',
        'priority',
        'min_order_amount',
        'services',
        'delivery_types',
        'payment_methods',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'min_order_amount' => 'decimal:2',
        'services' => 'array',
        'delivery_types' => 'array',
        'payment_methods' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Товары, к которым относится правило. Если список НЕ пуст — порог
     * считается по сумме только этих товаров в корзине (решение #2 спеки).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'free_shipping_rule_products',
            'free_shipping_rule_id',
            'product_id'
        );
    }

    /**
     * Страны действия правила. Связь через legacy-таблицу `country`
     * (подписанный bigint, есть запись с id = 0 — Россия).
     */
    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'free_shipping_rule_countries',
            'free_shipping_rule_id',
            'country_id'
        );
    }

    /**
     * Регионы действия правила (legacy-таблица `region`).
     */
    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(
            Region::class,
            'free_shipping_rule_regions',
            'free_shipping_rule_id',
            'region_id'
        );
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Активные правила с учётом окна действия.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function isActiveNow(): bool
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

        return true;
    }
}
