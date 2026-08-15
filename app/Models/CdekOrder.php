<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CdekOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id', 'shipment_id', 'cdek_uuid', 'cdek_number', 'request_uuid',
        'creation_state', 'status_code', 'status_name', 'internal_status',
        'delivery_type', 'delivery_mode', 'tariff_code', 'price', 'currency',
        'pvz_code', 'tracking_url', 'external_order_number', 'last_synced_at', 'last_error',
    ];

    protected $casts = ['price' => 'decimal:2', 'last_synced_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
}
