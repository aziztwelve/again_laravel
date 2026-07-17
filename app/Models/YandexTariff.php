<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YandexTariff extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'title', 'taxi_class', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean'];
}
