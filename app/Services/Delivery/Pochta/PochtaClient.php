<?php

namespace App\Services\Delivery\Pochta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент API «Отправка» Почты России.
 *
 * Авторизация двумя заголовками: `Authorization: AccessToken <токен>` и
 * `X-User-Authorization: Basic base64(логин:пароль)`. Секреты в логи не пишем:
 * при ошибке в лог попадают только путь, статус и начало ответа.
 */
class PochtaClient
{
    public function __construct(private array $settings)
    {
    }

    public function configured(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false)
            && filled($this->settings['token'] ?? null)
            && filled($this->settings['login'] ?? null)
            && filled($this->settings['password'] ?? null);
    }

    /** @return array{successful: bool, status: int, data: mixed} */
    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        if (! $this->configured()) {
            return ['successful' => false, 'status' => 503, 'data' => ['message' => 'Интеграция Почты России не настроена.']];
        }

        $url = rtrim((string) ($this->settings['base_url'] ?? 'https://otpravka-api.pochta.ru'), '/').'/'.ltrim($path, '/');
        $options = $method === 'GET' ? ['query' => $query + $payload] : ['query' => $query, 'json' => $payload];

        try {
            $response = Http::withHeaders($this->headers())->acceptJson()->timeout(15)->connectTimeout(5)->send($method, $url, $options);
        } catch (ConnectionException) {
            Log::warning('Pochta API: нет соединения', ['path' => $path]);

            return ['successful' => false, 'status' => 503, 'data' => ['message' => 'Не удалось подключиться к API Почты России.']];
        }

        if (! $response->successful()) {
            Log::warning('Pochta API: ошибка', ['path' => $path, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
        }

        return ['successful' => $response->successful(), 'status' => $response->status(), 'data' => $response->json() ?? []];
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'AccessToken '.$this->settings['token'],
            'X-User-Authorization' => 'Basic '.base64_encode($this->settings['login'].':'.$this->settings['password']),
            'Content-Type' => 'application/json;charset=UTF-8',
        ];
    }
}
