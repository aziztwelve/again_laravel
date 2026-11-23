<?php

namespace Tests\Feature\Delivery;

use App\Models\CdekApiLog;
use App\Services\Delivery\Cdek\CdekClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Опрос статуса посылки (каждые 10 минут по каждой «в пути») не должен писать в аудит-лог полный ответ СДЭК. */
class CdekPollLogSizeTest extends TestCase
{
    use DatabaseTransactions;

    private const UUID = '9eacb401-d3bd-4e6b-82fd-06d8b029549e';

    private function client(): CdekClient
    {
        Cache::flush();

        return new CdekClient([
            'enabled' => true, 'mode' => 'sandbox', 'account' => 'a', 'secure_password' => 's',
            'base_url' => ['sandbox' => 'https://api.edu.cdek.ru'],
        ]);
    }

    public function test_successful_status_poll_is_logged_compactly(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v2/orders/'.self::UUID => Http::response([
                'entity' => ['statuses' => [['code' => 'ACCEPTED'], ['code' => 'CREATED']], 'big' => str_repeat('x', 5000)],
                'requests' => [['state' => 'SUCCESSFUL']],
            ]),
        ]);

        $result = $this->client()->request('GET', '/v2/orders/'.self::UUID);

        $this->assertTrue($result['successful']);
        $this->assertArrayHasKey('entity', $result['data']);
        $log = CdekApiLog::query()->where('url', 'like', '%/v2/orders/'.self::UUID)->latest('id')->firstOrFail();
        $this->assertEquals(['compact' => true, 'status' => 'ACCEPTED', 'state' => 'SUCCESSFUL'], $log->response_body);
    }

    public function test_failed_status_poll_keeps_the_full_response(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v2/orders/'.self::UUID => Http::response(['errors' => [['code' => 'v2_entity_not_found', 'message' => 'нет']]], 404),
        ]);

        $this->client()->request('GET', '/v2/orders/'.self::UUID);

        $log = CdekApiLog::query()->where('url', 'like', '%/v2/orders/'.self::UUID)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $log->is_error);
        $this->assertArrayHasKey('errors', $log->response_body);
    }

    public function test_other_requests_are_still_logged_in_full(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v2/calculator/tarifflist' => Http::response(['tariff_codes' => [['tariff_code' => 136]]]),
        ]);

        $this->client()->request('POST', '/v2/calculator/tarifflist', ['x' => 1]);

        $log = CdekApiLog::query()->where('url', 'like', '%tarifflist')->latest('id')->firstOrFail();
        $this->assertArrayHasKey('tariff_codes', $log->response_body);
    }
}
