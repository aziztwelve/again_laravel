<?php

namespace Tests\Feature\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReadStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_toggle_a_conversation_read_status(): void
    {
        $admin = User::factory()->create();
        $conversation = Conversation::create([
            'source' => 'telegram',
            'external_id' => 'read-status-'.uniqid(),
            'status' => 'new',
            'last_message_at' => now(),
            'unread_messages_count' => 2,
        ]);
        $firstIncoming = $this->message($conversation, 'Первое', Message::STATUS_DELIVERED);
        $lastIncoming = $this->message($conversation, 'Последнее', Message::STATUS_DELIVERED);
        $outgoing = $this->message($conversation, 'Ответ менеджера', Message::STATUS_SENT, Message::DIRECTION_OUTGOING);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_messages_count', 0);

        $this->assertSame(Message::STATUS_READ, $firstIncoming->fresh()->status);
        $this->assertSame(Message::STATUS_READ, $lastIncoming->fresh()->status);
        $this->assertSame(Message::STATUS_SENT, $outgoing->fresh()->status);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/unread")
            ->assertOk()
            ->assertJsonPath('unread_messages_count', 1);

        $this->assertSame(Message::STATUS_READ, $firstIncoming->fresh()->status);
        $this->assertSame(Message::STATUS_DELIVERED, $lastIncoming->fresh()->status);
    }

    private function message(
        Conversation $conversation,
        string $content,
        string $status,
        string $direction = Message::DIRECTION_INCOMING,
    ): Message {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'content' => $content,
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'status' => $status,
        ]);
    }
}
