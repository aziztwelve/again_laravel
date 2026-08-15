<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CdekApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cdek_order_id', 'direction', 'method', 'http_method', 'url',
        'request_body', 'response_body', 'status_code', 'duration_ms', 'is_error',
    ];

    protected $casts = ['request_body' => 'array', 'response_body' => 'array', 'is_error' => 'boolean'];

    public function cdekOrder(): BelongsTo
    {
        return $this->belongsTo(CdekOrder::class);
    }
}
