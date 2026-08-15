<?php

namespace App\Services\Delivery\Cdek;

use App\Models\CdekApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdekClient
{
    public function __construct(private array $settings)
    {
    }

    /** @return array{successful: bool, status: int, data: array} */
    public function request(string $method, string $path, array $payload = [], array $query = [], ?int $cdekOrderId = null): array
    {
        if (! ($this->settings['enabled'] ?? false) || ! filled($this->settings['account'] ?? null) || ! filled($this->settings['secure_password'] ?? null)) {
            return $this->result(503, ['message' => 'Интеграция СДЭК не настроена.'], $method, $path, $this->url($path), $payload, $cdekOrderId, 0);
        }

        $token = $this->token($cdekOrderId);
        if (! $token) return $this->result(503, ['message' => 'Не удалось авторизоваться в СДЭК.'], $method, $path, $this->url($path), $payload, $cdekOrderId, 0);

        $response = $this->send($method, $path, $token, $payload, $query, $cdekOrderId);
        if ($response['status'] === 401) {
            Cache::forget($this->tokenKey());
            $token = $this->token($cdekOrderId);
            if ($token) $response = $this->send($method, $path, $token, $payload, $query, $cdekOrderId);
        }

        return $response;
    }

    private function token(?int $cdekOrderId = null): ?string
    {
        if ($token = Cache::get($this->tokenKey())) return $token;
        $payload = ['grant_type' => 'client_credentials', 'client_id' => $this->settings['account'], 'client_secret' => $this->settings['secure_password']];
        $url = $this->url('/v2/oauth/token');
        $started = hrtime(true);
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->connectTimeout(5)->post($url, $payload);
            $token = $response->json('access_token');
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->result($response->status(), is_array($response->json()) ? $response->json() : [], 'POST', '/v2/oauth/token', $url, $payload, $cdekOrderId, $duration);
            if (! $response->successful() || ! is_string($token) || $token === '') return null;

            $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
            Cache::put($this->tokenKey(), $token, now()->addSeconds($ttl));
            return $token;
        } catch (ConnectionException) {
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->result(503, ['message' => 'Не удалось подключиться к API СДЭК.'], 'POST', '/v2/oauth/token', $url, $payload, $cdekOrderId, $duration);
            return null;
        }
    }

    /** @return array{successful: bool, status: int, data: array} */
    private function send(string $method, string $path, string $token, array $payload, array $query, ?int $cdekOrderId): array
    {
        $url = $this->url($path);
        $started = hrtime(true);
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->timeout(20)->connectTimeout(5)->send($method, $url, $method === 'GET'
                ? ['query' => $query + $payload]
                : ['query' => $query, 'json' => $payload]);
            $data = $response->json();
            return $this->result($response->status(), is_array($data) ? $data : [], $method, $path, $url, $payload, $cdekOrderId, (int) ((hrtime(true) - $started) / 1_000_000));
        } catch (ConnectionException) {
            return $this->result(503, ['message' => 'Не удалось подключиться к API СДЭК.'], $method, $path, $url, $payload, $cdekOrderId, (int) ((hrtime(true) - $started) / 1_000_000));
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

    private function result(int $status, array $data, string $httpMethod, string $method, string $url, array $requestBody, ?int $cdekOrderId, int $duration): array
    {
        $successful = $status >= 200 && $status < 300;
        try {
            CdekApiLog::create([
                'cdek_order_id' => $cdekOrderId, 'direction' => 'request', 'method' => $method, 'http_method' => $httpMethod,
                'url' => $url, 'request_body' => $this->maskSensitive($requestBody), 'response_body' => $this->maskSensitive($data),
                'status_code' => $status, 'duration_ms' => $duration, 'is_error' => ! $successful,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to persist CDEK API audit log', ['method' => $method, 'error' => $exception->getMessage()]);
        }

        return ['successful' => $successful, 'status' => $status, 'data' => $data];
    }

    private function maskSensitive(array $value): array
    {
        $sensitiveKeys = ['access_token', 'token', 'authorization', 'client_id', 'client_secret', 'secure_password', 'email', 'phone', 'phones', 'name', 'address', 'comment'];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = $this->maskSensitive($item);
            }
        }
        return $value;
    }
}
