<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\UserProfile;
use App\Services\Client\GuestClientService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты авто-создания клиента при гостевом заказе.
 * См. docs/tasks/guest-client-auto-create.md.
 */
class GuestClientServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): GuestClientService
    {
        return app(GuestClientService::class);
    }

    private function payload(?string $email, ?string $phone): array
    {
        return [
            'user' => [
                'first_name' => 'Имя',
                'last_name' => 'Фамилия',
                'phone' => $phone,
                'email' => $email,
            ],
            'recipient' => [
                'first_name' => 'Имя',
                'last_name' => 'Фамилия',
                'phone' => $phone,
            ],
            'delivery_address' => [
                'address' => 'ул. Тестовая, 1',
                'region' => 'Московская область',
                'postal_code' => '123456',
            ],
        ];
    }

    public function test_creates_client_and_profile_from_guest_order_with_email(): void
    {
        $email = 'guest-lk-'.uniqid().'@example.com';

        $client = $this->service()->findOrCreateFromOrderData($this->payload($email, '+79991112233'));

        $this->assertNotNull($client);
        $this->assertSame($email, $client->email);
        // Клиент создан «без ЛК».
        $this->assertNull($client->verified_at);

        $profile = UserProfile::where('client_id', $client->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('Имя', $profile->first_name);
        $this->assertSame('+79991112233', $profile->phone);
        $this->assertSame('ул. Тестовая, 1', $profile->address);
    }

    public function test_does_not_duplicate_client_by_email(): void
    {
        $email = 'dedup-email-'.uniqid().'@example.com';

        $first = $this->service()->findOrCreateFromOrderData($this->payload($email, '+79991112233'));
        // Другой телефон, тот же email — должен найтись тот же клиент.
        $second = $this->service()->findOrCreateFromOrderData($this->payload($email, '+70000000000'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Client::where('email', $email)->count());
    }

    public function test_does_not_duplicate_client_by_phone_when_email_absent(): void
    {
        $email = 'dedup-phone-'.uniqid().'@example.com';

        $first = $this->service()->findOrCreateFromOrderData($this->payload($email, '+79991112233'));
        // Без email, тот же телефон в другом написании — дедуп по user_profiles.phone.
        $second = $this->service()->findOrCreateFromOrderData($this->payload(null, '+7 (999) 111-22-33'));

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
    }

    public function test_returns_null_when_no_email_and_no_phone(): void
    {
        $before = Client::count();

        $client = $this->service()->findOrCreateFromOrderData($this->payload(null, null));

        $this->assertNull($client);
        $this->assertSame($before, Client::count());
    }

    public function test_creates_separate_client_for_new_contacts(): void
    {
        $emailA = 'a-'.uniqid().'@example.com';
        $emailB = 'b-'.uniqid().'@example.com';

        $a = $this->service()->findOrCreateFromOrderData($this->payload($emailA, '+79995556677'));
        $b = $this->service()->findOrCreateFromOrderData($this->payload($emailB, '+79998889900'));

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_has_account_flag_reflects_verified_at(): void
    {
        $email = 'verify-'.uniqid().'@example.com';

        $client = $this->service()->findOrCreateFromOrderData($this->payload($email, '+79991112233'));
        $this->assertNull($client->verified_at, 'Новый гостевой клиент — без ЛК');

        // Эмуляция первого OTP-входа.
        $client->verified_at = now();
        $client->save();

        $this->assertNotNull($client->fresh()->verified_at, 'После входа — есть ЛК');
    }
}
