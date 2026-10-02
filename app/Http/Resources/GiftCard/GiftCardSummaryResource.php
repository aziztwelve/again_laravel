<?php

namespace App\Http\Resources\GiftCard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GiftCardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nominal' => (float) $this->nominal,
            'balance' => (float) $this->balance,
            'type' => $this->type,
            'status' => $this->status,

            'sender_name' => $this->sender_name,
            'sender_email' => $this->sender_email,
            'sender_phone' => $this->sender_phone,

            'recipient_name' => $this->recipient_name,
            'recipient_email' => $this->recipient_email,

            'delivery_channel' => $this->delivery_channel,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'is_sent' => $this->sent_at !== null,

            'purchase_order_number' => $this->purchaseOrder?->order_number,

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
