<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PromoCode;
use App\Models\UserProfile;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Traits\PhoneFormatterTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BirthdayDiscountCommand extends Command
{

    use PhoneFormatterTrait;

    protected $signature = 'birthday:process';
    protected $description = 'Обработать скидки на день рождения';

    public function handle(): int
    {
        try {
            $this->info('🎂 Обработка скидок на день рождения...');

            // Шаг 1: Найти клиентов у кого ДР за 3 дня
            $this->findAndCreateBirthdayDiscounts();

            // Шаг 2: Отправить напоминание за 1 день до окончания
            $this->sendReminderNotifications();

            // Шаг 3: Удалить использованные промокоды
            $this->removeBirthdayDiscounts();

            $this->info('✅ Обработка завершена успешно');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            Log::error('BirthdayDiscountCommand: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('❌ Ошибка при обработке: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Шаг 1: Найти клиентов у кого ДР за 3 дня и создать промокоды
     */
    protected function findAndCreateBirthdayDiscounts(): void
    {
        // Дата через 3 дня
        $birthdayDate = Carbon::now()->addDays(3)->toDateString();

        // Формат: 0821 (месяц-день)
        $birthdayMonth = Carbon::now()->addDays(3)->format('m');
        $birthdayDay = Carbon::now()->addDays(3)->format('d');

        $clients = UserProfile::whereRaw("DATE_FORMAT(birthday, '%m-%d') = ?", ["{$birthdayMonth}-{$birthdayDay}"])
            ->whereNotNull('client_id')
            ->with('client')
            ->get();

        $this->info("📅 Найдено клиентов с ДР через 3 дня: " . $clients->count());

        foreach ($clients as $profile) {
            try {

                // Проверяем есть ли уже промокод на ДР для этого клиента
                $existingPromo = $profile->client->promoCodes()
                    ->where('template_type', 'birthday')
                    ->wherePivot('birthday_discount', true)
                    ->wherePivot('notified_at', '>=', Carbon::now()->subDays(6))
                    ->first();

                if ($existingPromo) {
                    $this->info("⏭️  Клиент #{$profile->client_id} уже имеет ДР промокод");
                    continue;
                }

                // Получаем или создаём один промокод на ДР для всех клиентов
                $promoCode = $this->getBirthdayPromoCode();

                // Добавляем клиента к промокоду
                $profile->client->promoCodes()->attach($promoCode->id, [
                    'birthday_discount' => true,
                    'notified_at' => Carbon::now(),
                    'reminder_sent' => false,
                ]);

                // Отправляем уведомление
                $this->sendBirthdayNotification($profile);

                $this->info("✅ Клиент #{$profile->client_id} добавлен к ДР скидке");

            } catch (\Exception $e) {
                Log::error('BirthdayDiscountCommand: Error creating discount', [
                    'client_id' => $profile->client_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Шаг 2: Отправить напоминание за 1 день до окончания
     */
    protected function sendReminderNotifications(): void
    {
        // Найти промокоды которые были добавлены 5 дней назад и не отправили напоминание
        $fiveDaysAgo = Carbon::now()->subDays(5)->toDateString();

        $clientPromoCodes = \DB::table('promo_code_client')
            ->where('birthday_discount', true)
            ->where('reminder_sent', false)
            ->whereDate('notified_at', $fiveDaysAgo)
            ->get();

        $this->info("📢 Найдено промокодов для напоминания: " . $clientPromoCodes->count());

        foreach ($clientPromoCodes as $record) {
            try {
                $client = Client::find($record->client_id);
                $promoCode = PromoCode::find($record->promo_code_id);

                if (!$client || !$promoCode) {
                    continue;
                }

                // Проверяем использовал ли клиент промокод
                $used = \DB::table('promo_code_usages')
                    ->where('promo_code_id', $promoCode->id)
                    ->where('client_id', $client->id)
                    ->exists();

                if (!$used) {
                    // Отправляем напоминание
                    $this->sendReminderNotification($client, $promoCode);
                }

                // Отмечаем что напоминание отправлено
                \DB::table('promo_code_client')
                    ->where('id', $record->id)
                    ->update(['reminder_sent' => true]);

                $this->info("✅ Напоминание отправлено клиенту #{$client->id}");

            } catch (\Exception $e) {
                Log::error('BirthdayDiscountCommand: Error sending reminder', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Шаг 3: Удалить использованные промокоды (6 дней истекли)
     */
    protected function removeBirthdayDiscounts(): void
    {
        // Найти промокоды которые были добавлены 6 дней назад
        $sixDaysAgo = Carbon::now()->subDays(6)->toDateString();

        $clientPromoCodes = \DB::table('promo_code_client')
            ->where('birthday_discount', true)
            ->whereDate('notified_at', $sixDaysAgo)
            ->get();

        $this->info("🗑️  Найдено промокодов для удаления: " . $clientPromoCodes->count());

        foreach ($clientPromoCodes as $record) {
            \DB::table('promo_code_client')
                ->where('id', $record->id)
                ->delete();

            $this->info("✅ Промокод удалён для клиента #{$record->client_id}");
        }
    }

    /**
     * Получить или создать единый промокод на ДР
     */
    protected function getBirthdayPromoCode(): PromoCode
    {
        $today = Carbon::today();

        $promo = PromoCode::where('template_type', 'birthday')
            ->where('is_active', true)
            ->first();

        if ($promo) {
            return $promo;
        }

        // Создаём новый промокод на ДР
        return PromoCode::create([
            'code' => 'BIRTHDAY' . $today->format('Ymd'),
            'description' => 'Скидка на день рождения',
            'discount_amount' => 5, // 10% или 10 рублей (зависит от типа)
            'discount_type' => 'percentage', // или 'fixed'
            'discount_behavior' => 'stack',
            'starts_at' => $today,
            'expires_at' => $today->addDays(365),
            'max_uses' => null,
            'is_active' => true,
            'type' => 'all',
            'applies_to_all_products' => true,
            'applies_to_all_clients' => false,
            'template_type' => 'birthday',
        ]);
    }

    /**
     * Отправить уведомление о ДР скидке
     */
    protected function sendBirthdayNotification(UserProfile $profile): void
    {
        $clientName = $profile->first_name ?? $profile->client->email;




        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $promoPageUrl = $frontendUrl . '/profile/sales';

        // Универсальное текстовое сообщение для Email, VK, WhatsApp
        $message = "Здравствуйте {$clientName}, наша команда «Again» от души поздравляет вас с предстоящим днем рождения!\n\n" .
            "Желаем вам отличного настроения, радости и улыбок! Также от нас — промокод на товары в нашем магазине в честь дня рождения.\n\n" .
            "Важно: промокод действует за 3 дня до дня рождения и 3 дня после него! Не упустите оформить заказ по выгодной цене!\n\n" .
            "🎁 Чтобы посмотреть и использовать ваш промокод, перейдите по ссылке:\n" .
            "{$promoPageUrl}\n\n" .
            "С уважением, команда «Again»";


        // Email
        if ($profile->client->email) {
            SendNotificationJob::dispatch('email', $profile->client->email, $message, [
                'subject' => 'Поздравляем с днем рождения! 🎂',
            ]);
        }

        // Telegram
        if ($profile->telegram_user_id) {
            SendNotificationJob::dispatch('telegram', $profile->telegram_user_id, $message);
        }

        // VK
        if ($profile->vk_user_id) {
            SendNotificationJob::dispatch('vk', (string)$profile->vk_user_id, $message);
        }

        // WhatsApp
        if ($profile?->phone) {

            $phone = $this->formatPhoneForWhatsApp($profile->phone);

            SendNotificationJob::dispatch('whatsapp', $phone, $message);
        }

    }

    /**
     * Отправить напоминание за 1 день до окончания
     */
    protected function sendReminderNotification(Client $client, PromoCode $promoCode): void
    {
        $clientName = $client->profile?->first_name ?? $client->email;

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $promoPageUrl = $frontendUrl . '/profile/sales';

        $message = "Здравствуйте {$clientName}!\n" .
            "Напоминаем, что сегодня крайний день, когда вы можете воспользоваться своим промокодом на день рождения!\n" .
            "Не упустите оформить заказ по выгодной цене!\n" .
            "Посмотреть ваш промокод можно здесь: {$promoPageUrl}\n\n" .
            "С уважением, команда «Again»";

        // Email
        if ($client->email) {
            SendNotificationJob::dispatch('email', $client->email, $message, [
                'subject' => 'Крайний день использования вашей скидки на ДР! ⏰',
            ]);
        }

        // Telegram
        if ($client->profile?->telegram_user_id) {
            SendNotificationJob::dispatch('telegram', $client->profile->telegram_user_id, $message);
        }

//        // VK
//        if ($client->profile?->vk_user_id) {
//            SendNotificationJob::dispatch('vk', (string)$client->profile->vk_user_id, $message);
//        }
//
//        // WhatsApp
//        if ($client->profile?->phone) {
//            $phone = $this->formatPhoneForWhatsApp($client->profile->phone);
//
//            SendNotificationJob::dispatch('whatsapp', $phone, $message);
//        }

    }
}
