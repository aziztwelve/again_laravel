<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\BirthdayDiscountCommand;
use App\Models\Client;
use App\Models\PromoCode;
use App\Models\UserProfile;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

class BirthdayDiscountCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function clientWithChannels(): Client
    {
        $client = Client::create(['email' => 'birthday@example.com']);
        UserProfile::create([
            'client_id' => $client->id,
            'first_name' => 'Ирина',
            'telegram_user_id' => 123456,
            'max_user_id' => 234567,
            'vk_user_id' => 345678,
        ]);

        return $client->fresh('profile');
    }

    public function test_birthday_notification_uses_all_transactional_channels(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $this->invokeProtected('sendBirthdayNotification', $this->clientWithChannels()->profile);

        Bus::assertDispatched(SendNotificationJob::class, 4);
    }

    public function test_birthday_reminder_uses_all_transactional_channels(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $promoCode = new PromoCode;

        $this->invokeProtected('sendReminderNotification', $this->clientWithChannels(), $promoCode);

        Bus::assertDispatched(SendNotificationJob::class, 4);
    }

    private function invokeProtected(string $method, mixed ...$arguments): void
    {
        $reflection = new ReflectionMethod(BirthdayDiscountCommand::class, $method);
        $reflection->setAccessible(true);
        $reflection->invoke(app(BirthdayDiscountCommand::class), ...$arguments);
    }
}
