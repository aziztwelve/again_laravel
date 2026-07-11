<?php

namespace App\Services\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationConversationMirrorService
{
    public function mirror(array $data, string $message): void
    {
        $mirror = $data['mirror_conversation'] ?? null;
        if (!is_array($mirror)) {
            return;
        }

        $source = $mirror['source'] ?? null;
        $externalId = isset($mirror['external_id']) ? (string) $mirror['external_id'] : null;

        if (!$source || !$externalId) {
            return;
        }

        try {
            DB::transaction(function () use ($mirror, $source, $externalId, $data, $message) {
                $conversation = Conversation::firstOrCreate(
                    [
                        'source' => $source,
                        'external_id' => $externalId,
                    ],
                    [
                        'client_id' => $mirror['client_id'] ?? null,
                        'status' => 'active',
                        'last_message_at' => now(),
                        'unread_messages_count' => 0,
                    ]
                );

                if (($mirror['client_id'] ?? null) && !$conversation->client_id) {
                    $conversation->update(['client_id' => $mirror['client_id']]);
                }

                Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => Message::DIRECTION_OUTGOING,
                    'content' => $message,
                    'content_type' => Message::CONTENT_TYPE_TEXT,
                    'status' => Message::STATUS_SENT,
                    'source_data' => [
                        'kind' => 'notification',
                        'notification_type' => $data['type'] ?? null,
                        'order_id' => $data['order_id'] ?? null,
                        'gift_card_id' => $data['gift_card_id'] ?? null,
                    ],
                ]);

                $conversation->update([
                    'last_message_at' => now(),
                    'status' => $conversation->status === 'new' ? 'active' : $conversation->status,
                ]);
            });
        } catch (\Exception $e) {
            Log::warning('Failed to mirror notification to conversation', [
                'source' => $source,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
