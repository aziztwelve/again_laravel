<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись отправленной коммуникации по брошенной корзине (шаг цепочки
 * напоминаний). См. docs/tasks/abandoned-cart.md.
 */
class CartCommunication extends Model
{
    use HasFactory;

    protected $table = 'cart_communications';

    protected $guarded = ['id'];

    protected $casts = [
        'step' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
