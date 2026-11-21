<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Поиск клиентов: по почте не должен находиться «мусор» с похожими цифрами в телефоне. */
class ClientSearchTest extends TestCase
{
    use DatabaseTransactions;

    private function client(string $email, ?string $phone = null): Client
    {
        $client = Client::factory()->create(['email' => $email]);
        if ($phone) {
            $client->profile()->updateOrCreate([], ['first_name' => 'Тест', 'last_name' => 'Тестов', 'phone' => $phone]);
        }

        return $client;
    }

    private function search(string $q): array
    {
        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson('/api/clients?per_page=100&search='.urlencode($q))->assertOk();

        return collect($response->json('clients.data') ?? [])->pluck('id')->all();
    }

    public function test_email_search_does_not_match_unrelated_phones(): void
    {
        $match = $this->client('zzstasia1304@example.com');
        $junk = $this->client('zzother'.uniqid().'@example.com', '+79213045888');

        $ids = $this->search('zzstasia1304@example.com');

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($junk->id, $ids);
    }

    public function test_digits_still_find_a_phone(): void
    {
        $junk = $this->client('zzjunk'.uniqid().'@example.com', '+79990000001');
        $phone = $this->client('zzphone'.uniqid().'@example.com', '+79213045888');

        $ids = $this->search('3045888');

        $this->assertContains($phone->id, $ids);
        $this->assertNotContains($junk->id, $ids);
    }
}
