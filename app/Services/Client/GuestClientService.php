<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Log;

/**
 * Авто-создание клиента при гостевом оформлении заказа.
 *
 * Контекст: до этой задачи гостевой чекаут сознательно НЕ создавал запись в
 * `clients` (см. docs/tasks/guest-checkout.md). Клиентская база оставалась
 * «чистой» — только зарегистрированные пользователи. Теперь по решению бизнеса
 * гость всё равно попадает в раздел «Клиенты → Все клиенты», чтобы менеджеры
 * видели всю аудиторию магазина.
 *
 * Ключевые решения (см. docs/tasks/guest-client-auto-create.md):
 *  - Заказ остаётся ГОСТЕВЫМ: `orders.client_id = NULL`. Клиент создаётся как
 *    побочный эффект для наполнения базы, но не привязывается к заказу через FK.
 *  - Дедупликация: сначала по `clients.email`, затем по `user_profiles.phone`
 *    (та же логика, что в ClientImportService::findExistingClient).
 *  - Созданный клиент — «без ЛК»: без `password`, без `verified_at`. Признак
 *    «есть ЛК» (verified_at IS NOT NULL) проставится автоматически, когда клиент
 *    впервые войдёт по OTP (AuthenticatedSessionController::check_verification).
 */
class GuestClientService
{
    /**
     * Найти существующего или создать нового клиента из данных гостевого заказа.
     *
     * Никогда не бросает исключение наружу: создание клиента — вспомогательная
     * операция и НЕ должна ронять оформление заказа. При любой ошибке пишем в лог
     * и возвращаем null.
     *
     * @param  array  $orderData  Валидированный payload заказа (CreateOrderRequest)
     * @return Client|null Найденный/созданный клиент или null, если создавать не из чего
     */
    public function findOrCreateFromOrderData(array $orderData): ?Client
    {
        try {
            $user = $orderData['user'] ?? [];
            $recipient = $orderData['recipient'] ?? [];
            $deliveryAddress = $orderData['delivery_address'] ?? [];

            $email = $this->normalizeEmail($user['email'] ?? null);
            // Телефон покупателя: приоритет — получатель, затем контактный из user.
            $phone = $this->normalizePhone($recipient['phone'] ?? $user['phone'] ?? null);

            // Не из чего создавать/искать — нет ни email, ни телефона.
            if ($email === null && $phone === null) {
                return null;
            }

            $existing = $this->findExistingClient($email, $phone);
            if ($existing !== null) {
                return $existing;
            }

            return $this->createGuestClient($email, $user, $recipient, $deliveryAddress);
        } catch (\Throwable $e) {
            Log::warning('Guest client auto-create failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return null;
        }
    }

    /**
     * Поиск существующего клиента: сначала по email при совпадении или
     * отсутствии телефона в профиле,
     * затем по нормализованному телефону в user_profiles.
     */
    protected function findExistingClient(?string $email, ?string $phone): ?Client
    {
        if ($email !== null) {
            $client = Client::withTrashed()->with('profile')->where('email', $email)->first();
            // Один email может использоваться несколькими людьми (семья,
            // тестовый адрес, корпоративная почта). Если телефон получателя
            // передан и не совпадает с профилем, не приписываем заказ чужому
            // клиенту только по совпадению email. Если телефон у найденного
            // профиля ещё не заполнен, это не конфликт: дополняем профиль
            // телефоном из оформления и сохраняем уже привязанные каналы.
            $existingPhone = $client?->profile?->phone;
            if ($client && (
                $phone === null
                || $this->phoneDigits($existingPhone) === null
                || $this->phonesMatch($existingPhone, $phone)
            )) {
                if ($phone !== null && $this->phoneDigits($existingPhone) === null && $client->profile) {
                    $client->profile->update(['phone' => $phone]);
                }

                return $client;
            }
        }

        if ($phone !== null) {
            // Сопоставление по цифрам телефона: "+7 (912) 345-67-89" ≈ "89123456789".
            $digits = preg_replace('/\D+/', '', $phone);

            if ($digits !== '') {
                $clientId = UserProfile::query()
                    ->whereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', '') LIKE ?",
                        ["%{$digits}%"]
                    )
                    ->whereNotNull('client_id')
                    ->value('client_id');

                if ($clientId) {
                    return Client::withTrashed()->find($clientId);
                }
            }
        }

        return null;
    }

    private function phonesMatch(?string $left, ?string $right): bool
    {
        $left = $this->phoneDigits($left);
        $right = $this->phoneDigits($right);

        return $left !== null && $right !== null && $left === $right;
    }

    private function phoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        // 8XXXXXXXXXX и 7XXXXXXXXXX — один и тот же российский номер.
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Создать нового клиента «без ЛК» + профиль. Профиль создаётся через
     * связь profile() (hasOne по user_profiles.client_id) — так же, как в
     * ClientController::store.
     */
    protected function createGuestClient(
        ?string $email,
        array $user,
        array $recipient,
        array $deliveryAddress
    ): Client {
        // email/password/verified_at не заполняем принудительно:
        // password/verified_at остаются NULL → клиент «без ЛК».
        $client = Client::create([
            'email' => $email,
            'bonus_balance' => 0,
        ]);

        $client->profile()->create([
            'first_name' => $recipient['first_name'] ?? $user['first_name'] ?? null,
            'last_name' => $recipient['last_name'] ?? $user['last_name'] ?? null,
            'middle_name' => $recipient['middle_name'] ?? $user['middle_name'] ?? null,
            'phone' => $recipient['phone'] ?? $user['phone'] ?? null,
            'address' => $deliveryAddress['address'] ?? null,
            'delivery_region' => $deliveryAddress['region'] ?? null,
            'delivery_postal_code' => $deliveryAddress['postal_code'] ?? null,
        ]);

        Log::info('Guest client auto-created from order', [
            'client_id' => $client->id,
            'has_email' => $email !== null,
        ]);

        return $client;
    }

    protected function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? null : $email;
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        return $phone === '' ? null : $phone;
    }
}
