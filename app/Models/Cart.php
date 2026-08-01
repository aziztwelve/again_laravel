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
        'recovery_cycle' => 'integer',
        'total' => 'decimal:2',
        'total_original' => 'decimal:2',
        'total_discount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $cart): void {
            // Новая активность после старта цепочки должна начать новый цикл
            // восстановления. Иначе прежние записи коммуникаций блокируют
            // повторный первый шаг по уникальному ключу cart+step+channel.
            if (! $cart->wasChanged('last_activity_at') || $cart->getOriginal('abandoned_at') === null) {
                return;
            }

            $cart->forceFill([
                'status' => 'active',
                'abandoned_at' => null,
                'recovery_token' => null,
                'recovery_promo_code' => null,
                'recovery_cart_communication_id' => null,
            ])->saveQuietly();
        });
    }

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

    public function recoveryCommunication(): BelongsTo
    {
        return $this->belongsTo(CartCommunication::class, 'recovery_cart_communication_id');
    }
}
