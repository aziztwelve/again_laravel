<?php

namespace App\Services\Utm;

use App\Models\MarketingChannel;
use App\Models\Product;
use App\Models\UtmLink;
use App\Support\PublicUrl;
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

        if (! empty($payload['target_url'])) {
            $payload['target_url'] = $this->canonicalizeTargetUrl($payload['target_url']);
        }

        // utm_source по умолчанию = код канала (ig, tg, …).
        if (empty($payload['utm_source'])) {
            $payload['utm_source'] = $existing->utm_source ?? $channel->code;
        }

        return $payload;
    }

    private function canonicalizeTargetUrl(string $url): string
    {
        // Прежние домены проекта задаются в LEGACY_HOSTS, а не в коде:
        // см. App\Support\PublicUrl и config('app.legacy_hosts').
        $url = PublicUrl::canonicalize($url);

        $parts = parse_url($url);

        $path = $parts['path'] ?? '';

        if (! preg_match('#^/catalog/([^/]+)$#', $path, $matches)) {
            return $this->buildUrl($parts);
        }

        $product = Product::query()
            ->where('uuid', $matches[1])
            ->whereNotNull('slug')
            ->first(['slug']);

        if (! $product?->slug) {
            return $url;
        }

        $parts['path'] = '/catalog/'.$product->slug;

        return $this->buildUrl($parts);
    }

    private function buildUrl(array $parts): string
    {
        return PublicUrl::buildUrl($parts);
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
