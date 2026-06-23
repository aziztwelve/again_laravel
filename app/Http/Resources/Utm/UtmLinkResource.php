<?php

namespace App\Http\Resources\Utm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UtmLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'marketing_channel_id' => $this->marketing_channel_id,
            'channel' => new MarketingChannelResource($this->whenLoaded('channel')),
            'utm_tag_id' => $this->utm_tag_id,
            'tag' => new UtmTagResource($this->whenLoaded('tag')),
            'target_url' => $this->target_url,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_content' => $this->utm_content,
            'utm_term' => $this->utm_term,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            // Готовая ссылка-трекер для кнопки «копировать в буфер».
            'tracking_url' => $this->tracking_url,
            'target_url_with_params' => $this->target_url_with_params,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
