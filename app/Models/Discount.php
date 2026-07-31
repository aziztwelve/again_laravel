<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use SoftDeletes;

    public const CUSTOMER_TYPE_AUTHORIZED = 'authorized';
    public const CUSTOMER_TYPE_GUEST = 'guest';
    public const CUSTOMER_TYPE_ALL = 'all';

    protected $guarded = ['id'];

    protected $casts = [
        'conditions' => 'json',
        'is_active' => 'boolean',
        'is_manually_disabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'value' => 'decimal:2'
    ];

    public function isAvailableForCustomerType(?string $customerType): bool
    {
        $target = $this->customer_type ?: self::CUSTOMER_TYPE_ALL;

        return $target === self::CUSTOMER_TYPE_ALL || $target === $customerType;
    }

    public function products()
    {
        return $this->morphedByMany(Product::class, 'discountable');
    }

    public function productVariants()
    {
        return $this->morphedByMany(ProductVariant::class, 'discountable');
    }

    public function isValid(): bool
    {
        return $this->is_active
            && (!$this->starts_at || $this->starts_at->isPast())
            && (!$this->ends_at || $this->ends_at->isFuture());
    }

    public function categories()
    {
        return $this->morphedByMany(Category::class, 'discountable');
    }


    public function getIsUnlimitedAttribute(): bool
    {
        return $this->ends_at === null;
    }

    public function getEndsAtFormattedAttribute(): ?string
    {
        if ($this->ends_at === null) {
            return 'Бессрочно';
        }
        return $this->ends_at->format('d.m.Y');
    }

}
