<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cart extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "cart";

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'ordered_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'consent_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'total' => 'decimal:2',
        'total_original' => 'decimal:2',
        'total_discount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function communications(): HasMany
    {
        return $this->hasMany(CartCommunication::class);
    }
}
