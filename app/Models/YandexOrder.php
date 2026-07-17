<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class YandexOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id', 'shipment_id', 'claim_id', 'claim_version', 'status',
        'internal_status', 'delivery_type', 'tariff_code', 'price', 'currency',
        'offer_id', 'pvz_id', 'scheduled_time', 'performer_info', 'tracking_url',
        'request_id', 'cancel_state', 'last_synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'performer_info' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(YandexStatusEvent::class);
    }
}
