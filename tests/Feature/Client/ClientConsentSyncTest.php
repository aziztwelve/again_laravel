<?php

namespace Tests\Feature\Client;

use App\Http\Requests\Order\CreateOrderRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientConsentService;
use App\Services\Client\GuestClientService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientConsentSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function flags(bool $newsletter = true): array
    {
        return [
            'privacy_policy_accepted' => true,
            'personal_data_consent' => true,
            'oferta_accepted' => true,
            'subscribed_to_newsletter' => $newsletter,
        ];
    }

    public function test_profile_choices_are_persisted_and_returned_to_the_client(): void
    {
        $client = Client::factory()->create(['email' => uniqid('consent-').'@example.com']);
        $client->profile()->create(['first_name' => 'Consent', 'last_name' => 'Test']);
        Sanctum::actingAs($client);

        $this->putJson('/api/clients/update-profile', $this->flags() + [
            'first_name' => 'Consent', 'last_name' => 'Test',
        ])->assertOk()
            ->assertJsonPath('user.privacy_policy_accepted', true)
            ->assertJsonPath('user.oferta_accepted', true);

        $client->refresh();
        foreach ($this->flags() as $field => $value) {
            $this->assertSame($value, $client->{$field});
        }
        $confirmedAt = $client->personal_data_consent_at->toISOString();

        $this->putJson('/api/clients/update-profile', $this->flags(false) + [
            'first_name' => 'Consent', 'last_name' => 'Test',
        ])->assertOk()->assertJsonPath('user.subscribed_to_newsletter', false);
        $this->assertFalse($client->fresh()->subscribed_to_newsletter);
        $this->assertSame($confirmedAt, $client->fresh()->personal_data_consent_at->toISOString());
    }

    public function test_omitted_fields_preserve_imported_consents_and_notifications(): void
    {
        $client = Client::factory()->create([
            'personal_data_consent' => true,
            'subscribed_to_newsletter' => true,
            'messenger_subscription' => true,
        ]);

        app(ClientConsentService::class)->sync($client, []);
        $client->refresh();
        $this->assertTrue($client->personal_data_consent);
        $this->assertTrue($client->subscribed_to_newsletter);
        $this->assertTrue($client->messenger_subscription);
        $this->assertFalse($client->privacy_policy_accepted);
        $this->assertFalse($client->oferta_accepted);
        $this->assertNull($client->personal_data_consent_at);
    }

    public function test_admin_edit_saves_all_four_flags_without_resetting_order_notifications(): void
    {
        $client = Client::factory()->create([
            'email' => uniqid('admin-consent-').'@example.com',
            'messenger_subscription' => true,
        ]);
        $client->profile()->create(['first_name' => 'Consent', 'last_name' => 'Test']);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/clients/'.$client->id, $this->flags(false) + [
            'first_name' => 'Consent',
            'last_name' => 'Test',
            'email' => $client->email,
            'phone' => null,
            'address' => null,
            'birthday' => null,
        ])->assertOk()
            ->assertJsonPath('client.privacy_policy_accepted', true)
            ->assertJsonPath('client.personal_data_consent', true)
            ->assertJsonPath('client.oferta_accepted', true)
            ->assertJsonPath('client.subscribed_to_newsletter', false);

        $client->refresh();
        $this->assertTrue($client->messenger_subscription);
        $this->assertNull($client->personal_data_consent_at);
    }

    public function test_admin_changes_do_not_invent_a_customer_confirmation_date(): void
    {
        $client = Client::factory()->create();
        $service = app(ClientConsentService::class);
        $service->sync($client, $this->flags());
        $this->assertNull($client->fresh()->personal_data_consent_at);

        $service->sync($client, $this->flags(), true);
        $this->assertNotNull($client->fresh()->personal_data_consent_at);

        $service->sync($client, array_fill_keys(array_keys($this->flags()), false));
        $client->refresh();
        foreach (array_keys($this->flags()) as $field) {
            $this->assertFalse($client->{$field});
        }
        $this->assertNull($client->personal_data_consent_at);
    }

    public function test_guest_choices_update_the_same_client_on_repeat_checkout(): void
    {
        $payload = ['user' => ['email' => uniqid('guest-consent-').'@example.com']];
        $guests = app(GuestClientService::class);
        $service = app(ClientConsentService::class);
        $client = $guests->findOrCreateFromOrderData($payload);
        $this->assertNotNull($client);
        $service->sync($client, $this->flags(), true);

        $existing = $guests->findOrCreateFromOrderData($payload);
        $this->assertSame($client->id, $existing->id);
        $service->sync($existing, $this->flags(false), true);
        $client->refresh();
        $this->assertTrue($client->privacy_policy_accepted);
        $this->assertTrue($client->personal_data_consent);
        $this->assertTrue($client->oferta_accepted);
        $this->assertFalse($client->subscribed_to_newsletter);
    }

    public function test_checkout_rejects_false_required_consents_but_accepts_unsubscribe_and_legacy_payloads(): void
    {
        $rules = array_intersect_key((new CreateOrderRequest)->rules(), $this->flags());
        $this->assertFalse(Validator::make([], $rules)->fails());
        $this->assertFalse(Validator::make($this->flags(false), $rules)->fails());

        foreach (['privacy_policy_accepted', 'personal_data_consent', 'oferta_accepted'] as $field) {
            $data = $this->flags();
            $data[$field] = false;
            $this->assertTrue(Validator::make($data, $rules)->errors()->has($field));
        }
        $this->assertTrue(Validator::make(['subscribed_to_newsletter' => 'unexpected'], $rules)->fails());
    }
}
