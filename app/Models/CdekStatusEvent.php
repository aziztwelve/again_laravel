<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdekStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cdek_order_id', 'source', 'status_code', 'status_name', 'status_at', 'payload'];
    protected $casts = ['status_at' => 'datetime', 'payload' => 'array'];
    protected $appends = ['city'];

    /**
     * Город статуса (СДЭК возвращает его в statuses[] каждого события,
     * payload события хранит всю сущность заказа).
     */
    public function getCityAttribute(): ?string
    {
        $statuses = data_get($this->payload, 'statuses', []);
        if (! is_array($statuses) || $statuses === []) {
            return null;
        }

        $code = (string) $this->status_code;
        $at = $this->status_at?->format('Y-m-d H:i:s');

        foreach ($statuses as $status) {
            if ((string) data_get($status, 'code') !== $code) {
                continue;
            }
            $dateTime = filled(data_get($status, 'date_time'))
                ? \Illuminate\Support\Carbon::parse($status['date_time'])->format('Y-m-d H:i:s')
                : null;
            if ($at && $dateTime && $dateTime !== $at) {
                continue;
            }

            return data_get($status, 'city');
        }

        return null;
    }
}
