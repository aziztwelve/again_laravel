<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtmVisit extends Model
{
    protected $fillable = [
        'utm_link_id',
        'visited_at',
        'ip_address',
        'user_agent',
        'referrer',
        'visitor_hash',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(UtmLink::class, 'utm_link_id');
    }
}
