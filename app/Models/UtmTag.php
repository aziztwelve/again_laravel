<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UtmTag extends Model
{
    protected $fillable = [
        'name',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(UtmLink::class);
    }
}
