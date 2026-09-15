<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ConversationUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public Conversation $conversation;
    public ?Message $message;

    public function __construct(Conversation $conversation, ?Message $message = null)
    {
        $this->conversation = $conversation;
        $this->message = $message;
    }

    public function broadcastOn(): array
    {

        return [
            new PrivateChannel  ('admin.notifications')
        ];
    }

    public function broadcastWith(): array
    {
        $conversation = $this->conversation->loadMissing([
            'client.profile',
            'lastMessage',
        ]);
        $message = $this->message ?? $conversation->lastMessage;
        $content = trim(strip_tags((string) ($message?->content ?? '')));

        return [
            'conversation_id' => $conversation->id,
            'source' => $conversation->source,
            'last_message_at' => $conversation->last_message_at,
            // Эти поля позволяют панели показать уведомление без
            // дополнительного HTTP-запроса за каждым новым сообщением.
            'message_id' => $message?->id,
            'message_direction' => $message?->direction,
            'message_preview' => Str::limit($content ?: 'Вложение', 100),
            'client_name' => $conversation->client?->profile?->full_name ?: null,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationUpdated';
    }
}
