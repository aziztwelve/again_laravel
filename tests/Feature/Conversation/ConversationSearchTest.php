<?php

namespace Tests\Feature\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConversationSearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_numeric_search_returns_the_exact_conversation_id_when_it_exists(): void
    {
        $admin = User::factory()->create();
        $target = $this->conversation('target-'.uniqid(), now()->subMinute());
        $other = $this->conversation('other-'.uniqid(), now());

        Message::create([
            'conversation_id' => $target->id,
            'direction' => Message::DIRECTION_INCOMING,
            'content' => 'Сообщение целевого чата',
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'status' => Message::STATUS_DELIVERED,
        ]);
        Message::create([
            'conversation_id' => $other->id,
            'direction' => Message::DIRECTION_INCOMING,
            'content' => "В тексте есть номер {$target->id}",
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'status' => Message::STATUS_DELIVERED,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/conversations?search={$target->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    private function conversation(string $externalId, $lastMessageAt): Conversation
    {
        return Conversation::create([
            'source' => 'telegram',
            'external_id' => $externalId,
            'status' => 'new',
            'last_message_at' => $lastMessageAt,
            'unread_messages_count' => 0,
        ]);
    }
}
