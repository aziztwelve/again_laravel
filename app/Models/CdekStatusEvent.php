<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdekStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cdek_order_id', 'source', 'status_code', 'status_name', 'status_at', 'payload'];
    protected $casts = ['status_at' => 'datetime', 'payload' => 'array'];
}
