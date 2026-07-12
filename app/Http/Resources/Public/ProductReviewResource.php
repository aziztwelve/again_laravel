<?php

namespace App\Http\Resources\Public;

use App\Support\Reviews\PublicReviewContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $client = $this->relationLoaded('client') ? $this->client : null;
        $profile = $client?->relationLoaded('profile') ? $client->profile : null;

        return [
            'id' => (int) $this->id,
            'content' => PublicReviewContent::normalize($this->content),
            'rating' => (int) $this->rating,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'likes_count' => (int) ($this->likes_count ?? 0),
            'is_liked' => (bool) ($this->is_liked ?? false),
            'client' => $client ? [
                'name' => (string) ($profile?->first_name ?? ''),
            ] : null,
        ];
    }
}
