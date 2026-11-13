<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private function payload(array $override = []): array
    {
        return array_merge([
            'phone_number' => '227775403311132@lid',
            'message_text' => 'Здравствуйте',
            'message_id' => 'MSG' . uniqid(),
            'timestamp' => '2026-11-13T05:55:11.000Z',
            'from_id' => '227775403311132@lid',
        ], $override);
    }

    public function test_text_message_is_saved(): void
    {
        Event::fake();

        $this->postJson('/api/public/whatsapp/webhook', $this->payload())->assertOk();

        $conversation = Conversation::where('source', 'whatsapp')->where('external_id', '227775403311132@lid')->firstOrFail();
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'content' => 'Здравствуйте', 'direction' => 'incoming']);
    }

    public function test_message_without_text_is_saved_with_placeholder_instead_of_400(): void
    {
        Event::fake();

        $this->postJson('/api/public/whatsapp/webhook', $this->payload(['message_text' => '']))->assertOk();

        $conversation = Conversation::where('source', 'whatsapp')->where('external_id', '227775403311132@lid')->firstOrFail();
        $message = $conversation->messages()->latest('id')->firstOrFail();
        $this->assertStringContainsString('Сообщение без текста', $message->content);
    }

    public function test_missing_required_fields_still_rejected(): void
    {
        $this->postJson('/api/public/whatsapp/webhook', ['message_text' => 'x'])->assertStatus(400);
    }
}
