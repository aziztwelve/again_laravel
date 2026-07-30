<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class MediaFile extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->path);
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'media_fileable')
            ->withPivot(['position', 'is_main']);
    }

    public function variants(): MorphToMany
    {
        return $this->morphedByMany(ProductVariant::class, 'media_fileable')
            ->withPivot(['position', 'is_main']);
    }
}
