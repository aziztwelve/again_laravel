<?php

namespace App\Http\Resources\Client;

use App\Http\Resources\Segment\SegmentResource;
use App\Http\Resources\Tag\TagResource;
use App\Http\Resources\UserClient\UserProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'bonus_balance' => $this->bonus_balance,
            'subscribed_to_newsletter' => (bool) $this->subscribed_to_newsletter,
            'personal_data_consent' => (bool) $this->personal_data_consent,
            'messenger_subscription' => (bool) $this->messenger_subscription,
            'rfm_segment' => $this->rfm_segment,
            'group_name' => $this->group_name,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Profile
            'profile' => UserProfileResource::make($this->whenLoaded('profile')),

            // Level
            'level' => $this->level,

            // Tags
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'segments' => SegmentResource::collection($this->whenLoaded('segments')),

            // Last Order
            'last_order' => $this->whenLoaded('lastOrder', function () {
                return [
                    'id' => $this->lastOrder?->id,
                    'total_amount' => $this->lastOrder?->total_amount,
                    'created_at' => $this->lastOrder?->created_at?->toISOString(),
                ];
            }),
        ];
    }
}
