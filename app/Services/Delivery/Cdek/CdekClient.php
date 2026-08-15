<?php

namespace App\Services\Delivery\Cdek;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CdekClient
{
    public function __construct(private array $settings)
    {
    }

    /** @return array{successful: bool, status: int, data: array} */
    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        if (! ($this->settings['enabled'] ?? false) || ! filled($this->settings['account'] ?? null) || ! filled($this->settings['secure_password'] ?? null)) {
            return $this->result(503, ['message' => 'Интеграция СДЭК не настроена.']);
        }

        $token = $this->token();
        if (! $token) return $this->result(503, ['message' => 'Не удалось авторизоваться в СДЭК.']);

        $response = $this->send($method, $path, $token, $payload, $query);
        if ($response['status'] === 401) {
            Cache::forget($this->tokenKey());
            $token = $this->token();
            if ($token) $response = $this->send($method, $path, $token, $payload, $query);
        }

        return $response;
    }

    private function token(): ?string
    {
        if ($token = Cache::get($this->tokenKey())) return $token;
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->connectTimeout(5)->post($this->url('/v2/oauth/token'), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings['account'],
                'client_secret' => $this->settings['secure_password'],
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') return null;

            $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
            Cache::put($this->tokenKey(), $token, now()->addSeconds($ttl));
            return $token;
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @return array{successful: bool, status: int, data: array} */
    private function send(string $method, string $path, string $token, array $payload, array $query): array
    {
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->timeout(20)->connectTimeout(5)->send($method, $this->url($path), $method === 'GET'
                ? ['query' => $query + $payload]
                : ['query' => $query, 'json' => $payload]);
            $data = $response->json();
            return $this->result($response->status(), is_array($data) ? $data : []);
        } catch (ConnectionException) {
            return $this->result(503, ['message' => 'Не удалось подключиться к API СДЭК.']);
        }
    }

    private function url(string $path): string
    {
        $mode = $this->settings['mode'] ?? 'sandbox';
        return rtrim((string) ($this->settings['base_url'][$mode] ?? ''), '/').'/'.ltrim($path, '/');
    }

    private function tokenKey(): string
    {
        return 'cdek:oauth:'.($this->settings['mode'] ?? 'sandbox');
    }

    private function result(int $status, array $data): array
    {
        return ['successful' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
    }
}
