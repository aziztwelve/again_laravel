<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UtmLink extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'marketing_channel_id',
        'utm_tag_id',
        'target_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MarketingChannel::class, 'marketing_channel_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(UtmTag::class, 'utm_tag_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(UtmVisit::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Публичная ссылка-трекер вида https://site/go/{slug}.
     * Именно её менеджер копирует и раздаёт в канале.
     */
    public function getTrackingUrlAttribute(): string
    {
        return rtrim((string) config('utm.tracking_base_url', config('app.url')), '/').'/go/'.$this->slug;
    }

    /**
     * Целевой URL c прикреплёнными utm-параметрами (куда ведёт редирект).
     */
    public function getTargetUrlWithParamsAttribute(): string
    {
        $params = array_filter([
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_content' => $this->utm_content,
            'utm_term' => $this->utm_term,
        ], fn ($value) => $value !== null && $value !== '');

        if (empty($params)) {
            return $this->target_url;
        }

        $separator = str_contains($this->target_url, '?') ? '&' : '?';

        return $this->target_url.$separator.http_build_query($params);
    }
}
