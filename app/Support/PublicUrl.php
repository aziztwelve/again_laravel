<?php

namespace App\Support;

/**
 * Единая точка правды о публичном адресе проекта.
 *
 * Домен нигде не зашивается в код: канонический адрес берётся из
 * FRONTEND_URL / APP_URL, а список выведенных из эксплуатации хостов —
 * из LEGACY_HOSTS (config('app.legacy_hosts')). Благодаря этому переезд на
 * новый домен сводится к правке .env + `php artisan integrations:sync-webhooks`.
 */
class PublicUrl
{
    /** Канонический базовый URL без завершающего слэша. */
    public static function base(): string
    {
        $base = (string) (config('app.frontend_url') ?: config('app.url'));

        return rtrim($base, '/');
    }

    /** Канонический хост (без схемы), например `example.ru`. */
    public static function host(): ?string
    {
        return parse_url(self::base(), PHP_URL_HOST) ?: null;
    }

    /** Схема канонического адреса (`https` по умолчанию). */
    public static function scheme(): string
    {
        return parse_url(self::base(), PHP_URL_SCHEME) ?: 'https';
    }

    /**
     * Хост для текстов писем/сообщений («Магазин …»).
     * По умолчанию — канонический хост, переопределяется SHOP_PUBLIC_HOST.
     */
    public static function shopHost(): string
    {
        return (string) (config('app.shop_host') ?: self::host());
    }

    /** Абсолютный URL к пути внутри проекта. */
    public static function to(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    /** @return array<int, string> Список прежних хостов проекта. */
    public static function legacyHosts(): array
    {
        return (array) config('app.legacy_hosts', []);
    }

    public static function isLegacyHost(?string $host): bool
    {
        if (! $host) {
            return false;
        }

        return in_array(strtolower($host), array_map('strtolower', self::legacyHosts()), true);
    }

    /**
     * Переписывает URL с прежнего хоста проекта на канонический.
     * Внешние адреса (не из legacy-списка) не трогаются.
     */
    public static function canonicalize(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! self::isLegacyHost($parts['host'] ?? null)) {
            return $url;
        }

        $parts['host'] = self::host();
        $parts['scheme'] = self::scheme();
        unset($parts['port']);

        return self::buildUrl($parts);
    }

    /** @param array<string, mixed> $parts Результат parse_url(). */
    public static function buildUrl(array $parts): string
    {
        $url = ($parts['scheme'] ?? 'https').'://';

        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':'.$parts['pass'];
            }
            $url .= '@';
        }

        $url .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
