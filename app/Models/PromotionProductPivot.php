<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Связь акции с товаром (триггером или подарком).
 * variant_ids — с какими вариантами участвует товар; NULL — со всеми.
 */
class PromotionProductPivot extends Pivot
{
    public $incrementing = true;

    protected $casts = [
        'variant_ids' => 'array',
    ];
}
