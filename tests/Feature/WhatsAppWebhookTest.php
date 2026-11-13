<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserProfile;
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

    private function clientWithPhone(string $phone): Client
    {
        $client = Client::factory()->create();
        UserProfile::factory()->create(['user_id' => User::factory()->create()->id, 'client_id' => $client->id, 'phone' => $phone]);

        return $client;
    }

    public function test_sender_phone_and_name_are_saved_and_client_is_linked_by_phone(): void
    {
        Event::fake();
        $client = $this->clientWithPhone('8 (916) 123-45-67');

        $this->postJson('/api/public/whatsapp/webhook', $this->payload([
            'sender_phone' => '79161234567',
            'sender_name' => 'Анна',
        ]))->assertOk();

        $conversation = Conversation::where('source', 'whatsapp')->where('external_id', '227775403311132@lid')->firstOrFail();
        $this->assertSame('79161234567', $conversation->contact_phone);
        $this->assertSame('Анна', $conversation->contact_name);
        $this->assertSame($client->id, $conversation->client_id);
    }

    public function test_unknown_phone_is_saved_but_no_client_is_linked(): void
    {
        Event::fake();

        $this->postJson('/api/public/whatsapp/webhook', $this->payload(['sender_phone' => '79990001122', 'sender_name' => 'Гость']))->assertOk();

        $conversation = Conversation::where('external_id', '227775403311132@lid')->firstOrFail();
        $this->assertSame('79990001122', $conversation->contact_phone);
        $this->assertNull($conversation->client_id);
    }

    public function test_ambiguous_phone_is_not_linked_to_any_client(): void
    {
        Event::fake();
        $this->clientWithPhone('+7 903 555-11-22');
        $this->clientWithPhone('8-903-555-11-22');

        $this->postJson('/api/public/whatsapp/webhook', $this->payload(['sender_phone' => '79035551122']))->assertOk();

        $this->assertNull(Conversation::where('external_id', '227775403311132@lid')->firstOrFail()->client_id);
    }

    public function test_already_linked_client_is_not_overwritten(): void
    {
        Event::fake();
        $first = Client::factory()->create();
        Conversation::create(['source' => 'whatsapp', 'external_id' => '227775403311132@lid', 'client_id' => $first->id, 'status' => 'active', 'last_message_at' => now(), 'unread_messages_count' => 0]);
        $this->clientWithPhone('8 916 000-00-01');

        $this->postJson('/api/public/whatsapp/webhook', $this->payload(['sender_phone' => '79160000001']))->assertOk();

        $this->assertSame($first->id, Conversation::where('external_id', '227775403311132@lid')->firstOrFail()->client_id);
    }

    public function test_message_without_sender_info_still_works(): void
    {
        Event::fake();

        $this->postJson('/api/public/whatsapp/webhook', $this->payload())->assertOk();

        $conversation = Conversation::where('external_id', '227775403311132@lid')->firstOrFail();
        $this->assertNull($conversation->contact_phone);
        $this->assertNull($conversation->contact_name);
    }
}
