<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRestockSubscriptionHistory extends Model
{
    protected $fillable = [
        'product_restock_subscription_id',
        'user_id',
        'action',
        'description',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ProductRestockSubscription::class, 'product_restock_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
