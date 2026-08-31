<?php

namespace Tests\Feature\Conversation;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AttachClientTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_attach_client_to_anonymous_conversation(): void
    {
        $admin = User::factory()->create();
        $client = Client::create(['email' => 'chat-client-'.uniqid().'@example.com']);
        $conversation = Conversation::create([
            'source' => 'whatsapp',
            'external_id' => '79991112233@c.us',
            'status' => 'new',
            'last_message_at' => now(),
            'unread_messages_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/client", [
                'client_id' => $client->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('client.id', $client->id);

        $this->assertSame($client->id, $conversation->fresh()->client_id);
    }

    public function test_client_cannot_be_replaced_in_bound_conversation(): void
    {
        $admin = User::factory()->create();
        $firstClient = Client::create(['email' => 'first-chat-client-'.uniqid().'@example.com']);
        $secondClient = Client::create(['email' => 'second-chat-client-'.uniqid().'@example.com']);
        $conversation = Conversation::create([
            'source' => 'telegram',
            'external_id' => '123456789',
            'client_id' => $firstClient->id,
            'status' => 'new',
            'last_message_at' => now(),
            'unread_messages_count' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/client", [
                'client_id' => $secondClient->id,
            ])
            ->assertUnprocessable();

        $this->assertSame($firstClient->id, $conversation->fresh()->client_id);
    }
}
