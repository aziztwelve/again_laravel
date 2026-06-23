<?php

namespace App\Services\Utm;

use App\Models\MarketingChannel;
use App\Models\UtmLink;
use Illuminate\Support\Str;

class UtmLinkService
{
    /**
     * Создать UTM-метку. utm_source по умолчанию берётся из кода канала,
     * slug генерируется уникальным (используется в редирект-трекере /go/{slug}).
     */
    public function create(array $data): UtmLink
    {
        $channel = MarketingChannel::findOrFail($data['marketing_channel_id']);

        $payload = $this->normalize($data, $channel);
        $payload['slug'] = $this->generateUniqueSlug();

        return UtmLink::create($payload);
    }

    public function update(UtmLink $link, array $data): UtmLink
    {
        $channel = isset($data['marketing_channel_id'])
            ? MarketingChannel::findOrFail($data['marketing_channel_id'])
            : $link->channel;

        $link->update($this->normalize($data, $channel, $link));

        return $link->fresh(['channel', 'tag']);
    }

    /**
     * Нормализует входные данные: проставляет utm_source из канала,
     * если он не задан явно.
     */
    private function normalize(array $data, MarketingChannel $channel, ?UtmLink $existing = null): array
    {
        $payload = array_intersect_key($data, array_flip([
            'name',
            'marketing_channel_id',
            'utm_tag_id',
            'target_url',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'is_active',
        ]));

        // utm_source по умолчанию = код канала (ig, tg, …).
        if (empty($payload['utm_source'])) {
            $payload['utm_source'] = $existing->utm_source ?? $channel->code;
        }

        return $payload;
    }

    /**
     * Уникальный короткий slug (8 hex-символов) для ссылки /go/{slug}.
     */
    public function generateUniqueSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(8));
        } while (UtmLink::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
