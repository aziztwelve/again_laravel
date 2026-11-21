<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Клиент, созданный только по email (профиля нет): менеджер сохраняет данные в разделе «Клиенты».
 * Раньше ответ был «успешно», но ничего не сохранялось.
 */
class ClientUpdateWithoutProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manager_can_fill_in_a_client_that_has_no_profile(): void
    {
        $client = Client::factory()->create(['email' => 'no-profile-'.uniqid().'@example.com']);
        $client->profile()->delete();
        $this->assertNull($client->fresh()->profile);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->putJson("/api/clients/{$client->id}", [
            'email' => $client->email,
            'first_name' => 'Анастасия',
            'last_name' => 'Тестова',
            'phone' => '+79990001122',
        ]);
        $this->assertSame(200, $response->status(), (string) $response->getContent());

        $profile = $client->fresh()->profile;
        $this->assertNotNull($profile);
        $this->assertSame('Анастасия', $profile->first_name);
        $this->assertSame('Тестова', $profile->last_name);
        $this->assertSame('+79990001122', $profile->phone);
    }

    public function test_client_with_profile_is_still_updated_in_place(): void
    {
        $client = Client::factory()->create(['email' => 'with-profile-'.uniqid().'@example.com']);
        $client->profile()->updateOrCreate([], ['first_name' => 'Было', 'last_name' => 'Раньше']);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/clients/{$client->id}", [
            'email' => $client->email,
            'first_name' => 'Стало',
            'last_name' => 'Теперь',
        ])->assertOk();

        $this->assertSame(1, \App\Models\UserProfile::where('client_id', $client->id)->count());
        $this->assertSame('Стало', $client->fresh()->profile->first_name);
    }
}
