<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class AmneziaVpnService
{
    private const GROUP = 'amnezia_vpn';

    public function settings(): array
    {
        $settings = Setting::getGroup(self::GROUP);

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'scheme' => (string) ($settings['scheme'] ?? 'socks5h'),
            'host' => (string) ($settings['host'] ?? '85.159.228.227'),
            'port' => $settings['port'] ?? null,
            'username' => $settings['username'] ?? null,
            'has_password' => !empty($settings['password']),
            'last_check' => $settings['last_check'] ?? null,
        ];
    }

    public function update(array $data): array
    {
        $current = Setting::getGroup(self::GROUP);

        foreach (['enabled', 'scheme', 'host', 'port', 'username'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::set($key, $data[$key], self::GROUP);
            }
        }

        if (array_key_exists('password', $data) && filled($data['password'])) {
            Setting::set('password', encrypt($data['password']), self::GROUP);
        } elseif (!array_key_exists('password', $data) && array_key_exists('password', $current)) {
            Setting::set('password', $current['password'], self::GROUP);
        }

        return $this->settings();
    }

    public function telegramHttp(): PendingRequest
    {
        $request = Http::timeout(20);

        if ($this->settings()['enabled']) {
            $request = $this->applyProxy($request);
        }

        return $request;
    }

    public function applyProxy(PendingRequest $request): PendingRequest
    {
        $options = $this->proxyOptions();

        if (!$options) {
            return $request;
        }

        return $request->withOptions($options);
    }

    public function test(): array
    {
        $startedAt = now();
        $result = [
            'checked_at' => $startedAt->toIso8601String(),
            'proxy_configured' => $this->proxyOptions() !== null,
            'external_ip' => null,
            'telegram_status' => null,
            'ok' => false,
            'message' => null,
        ];

        try {
            $http = $this->applyProxy(Http::timeout(20));

            $ipResponse = $http->get('https://api.ipify.org', [
                'format' => 'json',
            ]);

            $result['external_ip'] = Arr::get($ipResponse->json() ?? [], 'ip');

            $telegramResponse = $http->get('https://api.telegram.org');
            $result['telegram_status'] = $telegramResponse->status();
            $result['ok'] = $ipResponse->ok() && $telegramResponse->status() < 500;
            $result['message'] = $result['ok']
                ? 'Proxy connection is available.'
                : 'Proxy responded, but one of the checks failed.';
        } catch (\Throwable $e) {
            $result['message'] = $e->getMessage();
        }

        Setting::set('last_check', $result, self::GROUP);

        return $result;
    }

    public function proxyOptions(): ?array
    {
        $settings = Setting::getGroup(self::GROUP);
        $scheme = (string) ($settings['scheme'] ?? 'socks5h');
        $host = trim((string) ($settings['host'] ?? ''));
        $port = $settings['port'] ?? null;

        if ($host === '' || empty($port)) {
            return null;
        }

        $username = trim((string) ($settings['username'] ?? ''));
        $password = $this->decryptPassword($settings['password'] ?? null);

        if (in_array($scheme, ['socks5', 'socks5h'], true)) {
            $curlOptions = [
                CURLOPT_PROXY => "{$host}:{$port}",
                CURLOPT_PROXYTYPE => $scheme === 'socks5h'
                    ? CURLPROXY_SOCKS5_HOSTNAME
                    : CURLPROXY_SOCKS5,
            ];

            if ($username !== '') {
                $curlOptions[CURLOPT_PROXYUSERPWD] = $username . ':' . ($password ?? '');
            }

            return ['curl' => $curlOptions];
        }

        $auth = '';
        if ($username !== '') {
            $auth = rawurlencode($username);
            if ($password !== null && $password !== '') {
                $auth .= ':' . rawurlencode($password);
            }
            $auth .= '@';
        }

        return [
            'proxy' => "{$scheme}://{$auth}{$host}:{$port}",
        ];
    }

    private function decryptPassword(?string $encrypted): ?string
    {
        if (!$encrypted) {
            return null;
        }

        try {
            return decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
