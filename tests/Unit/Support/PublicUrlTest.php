<?php

namespace Tests\Unit\Support;

use App\Support\PublicUrl;
use Tests\TestCase;

class PublicUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.com',
            'app.frontend_url' => 'https://shop.example.com',
            'app.legacy_hosts' => ['old.example.com', 'Older.Example.com'],
            'app.shop_host' => null,
        ]);
    }

    public function test_base_and_host_are_taken_from_frontend_url(): void
    {
        self::assertSame('https://shop.example.com', PublicUrl::base());
        self::assertSame('shop.example.com', PublicUrl::host());
        self::assertSame('https', PublicUrl::scheme());
    }

    public function test_base_falls_back_to_app_url(): void
    {
        config(['app.frontend_url' => null]);

        self::assertSame('https://api.example.com', PublicUrl::base());
    }

    public function test_to_builds_absolute_url_without_double_slash(): void
    {
        config(['app.frontend_url' => 'https://shop.example.com/']);

        self::assertSame('https://shop.example.com/api/public/vk/webhook', PublicUrl::to('/api/public/vk/webhook'));
        self::assertSame('https://shop.example.com/go/abc', PublicUrl::to('go/abc'));
    }

    public function test_canonicalize_rewrites_legacy_host_case_insensitively(): void
    {
        self::assertSame(
            'https://shop.example.com/catalog?foo=bar',
            PublicUrl::canonicalize('https://old.example.com/catalog?foo=bar')
        );

        self::assertSame(
            'https://shop.example.com/storage/a.jpg',
            PublicUrl::canonicalize('http://OLDER.example.com/storage/a.jpg')
        );
    }

    public function test_canonicalize_keeps_current_and_external_hosts_untouched(): void
    {
        self::assertSame(
            'https://shop.example.com/catalog',
            PublicUrl::canonicalize('https://shop.example.com/catalog')
        );

        self::assertSame(
            'https://partner.example.org/landing',
            PublicUrl::canonicalize('https://partner.example.org/landing')
        );

        self::assertNull(PublicUrl::canonicalize(null));
        self::assertSame('', PublicUrl::canonicalize(''));
    }

    public function test_legacy_hosts_config_is_empty_by_default(): void
    {
        config(['app.legacy_hosts' => []]);

        self::assertSame([], PublicUrl::legacyHosts());
        self::assertFalse(PublicUrl::isLegacyHost('old.example.com'));
        self::assertSame(
            'https://old.example.com/catalog',
            PublicUrl::canonicalize('https://old.example.com/catalog')
        );
    }

    public function test_shop_host_defaults_to_canonical_host_and_can_be_overridden(): void
    {
        self::assertSame('shop.example.com', PublicUrl::shopHost());

        config(['app.shop_host' => 'brand.example.ru']);

        self::assertSame('brand.example.ru', PublicUrl::shopHost());
    }
}
