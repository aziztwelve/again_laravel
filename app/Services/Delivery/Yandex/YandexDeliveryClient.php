<?php

namespace App\Services\Delivery\Yandex;

use App\Models\YandexApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexDeliveryClient
{
    public function __construct(private array $settings)
    {
    }

    /** @return array{successful: bool, status: int, data: array, body: string} */
    public function request(string $httpMethod, string $method, array $payload = [], ?int $orderId = null, ?string $requestId = null, array $query = []): array
    {
        $baseUrl = rtrim((string) ($this->settings['base_url'] ?? ''), '/');
        if (!$this->settings['enabled'] || !$this->settings['token'] || !$baseUrl) {
            return $this->result(503, ['message' => 'Интеграция Яндекс.Доставки не настроена.'], $httpMethod, $method, '', $payload, $orderId, $requestId, 0);
        }

        $url = $baseUrl.'/'.ltrim($method, '/');
        $started = hrtime(true);
        try {
            $request = Http::acceptJson()->asJson()->withToken($this->settings['token'])->timeout(20)->connectTimeout(5);
            $request = $request->withHeaders(['Accept-Language' => 'ru']);
            $response = $request->send($httpMethod, $url, $httpMethod === 'GET'
                ? ['query' => $query + $payload]
                : ['query' => $query, 'json' => $payload]);
            $data = $response->json();
            $data = is_array($data) ? $data : [];
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);
            return $this->result($response->status(), $data, $httpMethod, $method, $url, $payload, $orderId, $requestId, $duration, $response->body());
        } catch (ConnectionException $exception) {
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);
            Log::warning('Yandex Delivery API connection failed', ['method' => $method, 'error' => $exception->getMessage()]);
            return $this->result(503, ['message' => 'Не удалось подключиться к API Яндекс.Доставки.'], $httpMethod, $method, $url, $payload, $orderId, $requestId, $duration);
        }
    }

    private function result(int $status, array $data, string $httpMethod, string $method, string $url, array $requestBody, ?int $orderId, ?string $requestId, int $duration, ?string $body = null): array
    {
        $successful = $status >= 200 && $status < 300;
        YandexApiLog::create([
            'order_id' => $orderId, 'claim_id' => $requestId, 'direction' => 'request',
            'method' => $method, 'http_method' => $httpMethod, 'url' => $url,
            'request_body' => $this->maskSensitive($requestBody),
            'response_body' => $this->maskSensitive($data ?: ['raw' => $body]),
            'status_code' => $status, 'duration_ms' => $duration, 'is_error' => !$successful,
        ]);

        return ['successful' => $successful, 'status' => $status, 'data' => $data, 'body' => $body ?? ''];
    }

    private function maskSensitive(array $value): array
    {
        $sensitiveKeys = [
            'access_token', 'token', 'email', 'phone', 'courier_phone',
            'first_name', 'last_name', 'patronymic', 'address', 'comment',
        ];

        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->maskSensitive($item);
            }
        }

        return $value;
    }
}
