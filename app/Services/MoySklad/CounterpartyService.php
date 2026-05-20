<?php

namespace App\Services\MoySklad;

use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис работы с контрагентами (counterparty) в МойСклад.
 *
 * Ищет существующего контрагента по email или телефону.
 * Если не найден — создаёт нового.
 *
 * Используется внутри OrderService при выгрузке заказов.
 */
class CounterpartyService
{
    private string $baseURL = 'https://api.moysklad.ru/api/remap/1.2';
    private string $token;

    public function __construct()
    {
        $settings = \App\Models\DeliveryServiceSetting
            ::where('service_name', 'moysklad')
            ->first();

        if (! $settings) {
            throw new Exception('Настройки для МойСклад не найдены. Пожалуйста, настройте сервис в админке.');
        }

        $this->token = $settings->token;
    }

    // -------------------------------------------------------------------------
    // Публичный API
    // -------------------------------------------------------------------------

    /**
     * Найти или создать контрагента в МойСклад по данным заказа.
     * Возвращает meta-объект контрагента.
     *
     * @param  Order  $order
     * @return array  meta-объект { href, type, mediaType }
     *
     * @throws Exception
     */
    public function findOrCreateMeta(Order $order): array
    {
        // Извлекаем контактные данные из заказа
        $contact = $this->extractContactData($order);

        // 1. Ищем по email
        if ($contact['email']) {
            $meta = $this->findByEmail($contact['email']);
            if ($meta) {
                return $meta;
            }
        }

        // 2. Ищем по телефону
        if ($contact['phone']) {
            $meta = $this->findByPhone($contact['phone']);
            if ($meta) {
                return $meta;
            }
        }

        // 3. Не нашли — создаём нового
        return $this->create($contact);
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Извлечь контактные данные из заказа.
     * Приоритет: получатель (address) > клиент (client > profile) > гостевые поля заказа.
     *
     * @param  Order  $order
     * @return array { name, email, phone }
     */
    private function extractContactData(Order $order): array
    {
        $addr    = $order->address;
        $client  = $order->client;
        $profile = $client?->profile;

        // Имя
        $firstName  = $addr?->recipient_first_name
            ?? $profile?->first_name
            ?? null;
        $lastName   = $addr?->recipient_last_name
            ?? $profile?->last_name
            ?? null;
        $middleName = $addr?->recipient_middle_name
            ?? $profile?->middle_name
            ?? null;

        $nameParts = array_filter([$lastName, $firstName, $middleName]);
        $fullName  = implode(' ', $nameParts) ?: 'Покупатель';

        // Телефон
        $phone = $addr?->recipient_phone
            ?? $profile?->phone
            ?? null;

        // Email
        $email = $client?->email
            ?? $order->email
            ?? null;

        return [
            'name'  => $fullName,
            'email' => $email,
            'phone' => $phone ? $this->normalizePhone($phone) : null,
        ];
    }

    /**
     * Поиск контрагента в МойСклад по email.
     *
     * @param  string  $email
     * @return array|null  meta или null если не найден
     */
    private function findByEmail(string $email): ?array
    {
        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get("{$this->baseURL}/entity/counterparty", [
            'filter' => "email={$email}",
            'limit'  => 1,
        ]);

        if (! $response->successful()) {
            Log::warning('MoySklad: ошибка поиска контрагента по email', [
                'email' => $email,
                'body'  => $response->body(),
            ]);
            return null;
        }

        $rows = $response->json('rows') ?? [];

        if (empty($rows)) {
            return null;
        }

        return $rows[0]['meta'];
    }

    /**
     * Поиск контрагента в МойСклад по телефону.
     *
     * @param  string  $phone
     * @return array|null  meta или null если не найден
     */
    private function findByPhone(string $phone): ?array
    {
        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
        ])->get("{$this->baseURL}/entity/counterparty", [
            'filter' => "phone={$phone}",
            'limit'  => 1,
        ]);

        if (! $response->successful()) {
            Log::warning('MoySklad: ошибка поиска контрагента по телефону', [
                'phone' => $phone,
                'body'  => $response->body(),
            ]);
            return null;
        }

        $rows = $response->json('rows') ?? [];

        if (empty($rows)) {
            return null;
        }

        return $rows[0]['meta'];
    }

    /**
     * Создать нового контрагента в МойСклад.
     *
     * @param  array  $contact  { name, email, phone }
     * @return array  meta созданного контрагента
     *
     * @throws Exception
     */
    private function create(array $contact): array
    {
        $payload = [
            'name'         => $contact['name'],
            'companyType'  => 'individual', // физическое лицо
        ];

        if ($contact['email']) {
            $payload['email'] = $contact['email'];
        }

        if ($contact['phone']) {
            $payload['phone'] = $contact['phone'];
        }

        $response = Http::withHeaders([
            'Authorization'   => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type'    => 'application/json',
        ])->post("{$this->baseURL}/entity/counterparty", $payload);

        if (! $response->successful()) {
            Log::error('MoySklad: ошибка создания контрагента', [
                'payload' => $payload,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);

            throw new Exception(
                'МойСклад: не удалось создать контрагента. ' . ($response->json('errors.0.error') ?? $response->body())
            );
        }

        Log::info('MoySklad: создан новый контрагент', [
            'name'  => $contact['name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'],
        ]);

        return $response->json('meta');
    }

    /**
     * Нормализовать номер телефона — оставить только цифры и ведущий +.
     *
     * @param  string  $phone
     * @return string
     */
    private function normalizePhone(string $phone): string
    {
        // Убираем всё кроме цифр и +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Если начинается с 8 — заменяем на +7
        if (str_starts_with($cleaned, '8') && strlen($cleaned) === 11) {
            $cleaned = '+7' . substr($cleaned, 1);
        }

        // Если начинается с 7 без + — добавляем +
        if (str_starts_with($cleaned, '7') && strlen($cleaned) === 11) {
            $cleaned = '+' . $cleaned;
        }

        return $cleaned;
    }
}
