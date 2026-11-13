<?php

namespace Tests\Feature\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppReplyTest extends TestCase
{
    use DatabaseTransactions;

    private function conversation(): Conversation
    {
        return Conversation::create([
            'source' => 'whatsapp',
            'external_id' => '255581155803345@lid',
            'status' => 'active',
            'last_message_at' => now(),
            'unread_messages_count' => 0,
        ]);
    }

    public function test_reply_is_blocked_and_nothing_saved_when_whatsapp_is_disconnected(): void
    {
        Http::fake(['*/status' => Http::response(['ready' => false, 'hasQR' => true])]);
        Sanctum::actingAs(User::factory()->create());
        $conversation = $this->conversation();

        $this->postJson("/api/conversations/{$conversation->id}/reply", ['content' => 'Здравствуйте'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'WhatsApp не подключён'));

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/send-message'));
    }

    public function test_reply_is_blocked_when_whatsapp_service_is_unreachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        Sanctum::actingAs(User::factory()->create());
        $conversation = $this->conversation();

        $this->postJson("/api/conversations/{$conversation->id}/reply", ['content' => 'Привет'])->assertStatus(409);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    }

    public function test_reply_is_sent_and_marked_sent_when_connected(): void
    {
        Http::fake([
            '*/status' => Http::response(['ready' => true, 'hasQR' => false]),
            '*/send-message' => Http::response(['success' => true]),
        ]);
        Sanctum::actingAs(User::factory()->create());
        $conversation = $this->conversation();

        $this->postJson("/api/conversations/{$conversation->id}/reply", ['content' => 'Добрый день'])->assertCreated();

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'content' => 'Добрый день', 'direction' => 'outgoing', 'status' => 'sent']);
    }

    public function test_message_is_marked_failed_and_error_returned_when_service_refuses(): void
    {
        Http::fake([
            '*/status' => Http::response(['ready' => true, 'hasQR' => false]),
            '*/send-message' => Http::response(['success' => false, 'error' => 'Chat not found'], 500),
        ]);
        Sanctum::actingAs(User::factory()->create());
        $conversation = $this->conversation();

        $this->postJson("/api/conversations/{$conversation->id}/reply", ['content' => 'Не дойдёт'])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'content' => 'Не дойдёт', 'status' => 'failed']);
    }
}
