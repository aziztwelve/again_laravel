<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingChannel extends Model
{
    protected $fillable = [
        'name',
        'code',
        'is_system',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(UtmLink::class);
    }
}
