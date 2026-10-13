<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Services\Import\ClientConsentRestoreService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClientConsentRestoreTest extends TestCase
{
    use DatabaseTransactions;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function csv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'consent-csv-');
        $this->files[] = $file;
        $handle = fopen($file, 'w');
        fputcsv($handle, ['E-mail', 'Телефон', 'Согласие на обработку персональных данных', 'Подписка на новости', 'подписан на рассылку'], "\t");
        foreach ($rows as $row) {
            fputcsv($handle, $row, "\t");
        }
        fclose($handle);
        return $file;
    }

    public function test_dry_run_then_restore_changes_only_two_flags_and_is_idempotent(): void
    {
        $client = Client::factory()->create([
            'email' => uniqid('restore-').'@example.com',
            'personal_data_consent' => false,
            'subscribed_to_newsletter' => false,
            'privacy_policy_accepted' => true,
            'oferta_accepted' => false,
            'messenger_subscription' => true,
        ]);
        $file = $this->csv([[strtoupper($client->email), '', 'Да', 'Да', 'Да']]);
        $service = app(ClientConsentRestoreService::class);
        $plan = $service->plan($file);
        $this->assertSame(1, $plan['stats']['changes']);
        $this->assertFalse($client->fresh()->personal_data_consent);
        $this->assertSame(1, $service->apply($plan));
        $client->refresh();
        $this->assertTrue($client->personal_data_consent);
        $this->assertTrue($client->subscribed_to_newsletter);
        $this->assertTrue($client->privacy_policy_accepted);
        $this->assertFalse($client->oferta_accepted);
        $this->assertTrue($client->messenger_subscription);
        $this->assertNull($client->personal_data_consent_at);
        $this->assertSame(0, $service->plan($file)['stats']['changes']);
    }

    public function test_ambiguous_and_conflicting_rows_are_skipped_and_phone_is_normalized(): void
    {
        $email = uniqid('duplicate-').'@example.com';
        Client::factory()->create(['email' => $email]);
        Client::factory()->create(['email' => $email]);
        $phoneClient = Client::factory()->create(['email' => uniqid('phone-').'@example.com']);
        $phoneClient->profile()->create(['phone' => '+7 (999) 555-12-34']);
        $conflict = Client::factory()->create(['email' => uniqid('conflict-').'@example.com']);
        $file = $this->csv([
            [$email, '', 'Да', 'Да', 'Да'],
            ['', '8 999 555 12 34', 'Да', 'Нет', 'Нет'],
            [$conflict->email, '', 'Да', 'Да', 'Да'],
            [$conflict->email, '', 'Да', 'Нет', 'Нет'],
            [uniqid('missing-').'@example.com', '', 'Да', 'Да', 'Да'],
        ]);
        $plan = app(ClientConsentRestoreService::class)->plan($file);
        $this->assertSame(1, $plan['stats']['ambiguous']);
        $this->assertSame(1, $plan['stats']['conflicting_clients']);
        $this->assertSame(1, $plan['stats']['unmatched']);
        $this->assertSame([$phoneClient->id], array_keys($plan['changes']));
        $this->assertFalse($plan['changes'][$phoneClient->id]['after']['subscribed_to_newsletter']);
    }

    public function test_invalid_values_and_disagreement_between_newsletter_columns_are_skipped(): void
    {
        $client = Client::factory()->create(['email' => uniqid('invalid-').'@example.com']);
        $plan = app(ClientConsentRestoreService::class)->plan($this->csv([
            [$client->email, '', '', 'Да', 'Да'],
            [$client->email, '', 'Да', 'Да', 'Нет'],
        ]));
        $this->assertSame(2, $plan['stats']['invalid']);
        $this->assertSame([], $plan['changes']);
    }

    public function test_concurrent_change_aborts_restoration(): void
    {
        $client = Client::factory()->create(['email' => uniqid('concurrent-').'@example.com']);
        $service = app(ClientConsentRestoreService::class);
        $plan = $service->plan($this->csv([[$client->email, '', 'Да', 'Да', 'Да']]));
        $client->update(['subscribed_to_newsletter' => true]);
        $this->expectException(\RuntimeException::class);
        $service->apply($plan);
    }
}
