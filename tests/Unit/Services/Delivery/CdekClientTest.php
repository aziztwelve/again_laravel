<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\Cdek\CdekClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdekClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_authenticates_and_sends_bearer_token(): void
    {
        Http::fake([
            'https://api.edu.cdek.ru/v2/oauth/token' => Http::response([
                'access_token' => 'test-token', 'expires_in' => 3600,
            ]),
            'https://api.edu.cdek.ru/v2/location/suggest/cities*' => Http::response([['code' => 44]]),
        ]);

        $client = new CdekClient([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'account', 'secure_password' => 'secret',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
        ]);
        $result = $client->request('GET', '/v2/location/suggest/cities', query: ['query' => 'Москва']);

        $this->assertTrue($result['successful']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.edu.cdek.ru/v2/location/suggest/cities?query=%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_rejects_requests_when_not_configured(): void
    {
        $result = (new CdekClient(['enabled' => false]))->request('GET', '/v2/deliverypoints');

        $this->assertFalse($result['successful']);
        $this->assertSame(503, $result['status']);
    }
}
