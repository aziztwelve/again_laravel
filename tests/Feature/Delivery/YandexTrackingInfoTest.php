<?php

namespace Tests\Feature\Delivery;

use App\Models\YandexApiLog;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexTrackingInfoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_request_info_uses_full_response_and_masks_personal_log_data(): void
    {
        config([
            'services.yandex_delivery.enabled' => true,
            'services.yandex_delivery.mode' => 'sandbox',
            'services.yandex_delivery.token' => 'test-token',
            'services.yandex_delivery.base_url.sandbox' => 'https://yandex.test',
        ]);
        Http::fake([
            'https://yandex.test/*' => Http::response([
                'request_id' => 'request-1',
                'sharing_url' => 'https://dostavka.yandex.ru/route/test-route',
                'request' => ['recipient_info' => [
                    'first_name' => 'Иван',
                    'phone' => '+79999999999',
                    'email' => 'customer@example.test',
                ]],
            ]),
        ]);

        $result = app(YandexDeliveryService::class)->getRequestInfo('request-1');

        $this->assertTrue($result['successful']);
        $this->assertSame('https://dostavka.yandex.ru/route/test-route', $result['data']['sharing_url']);
        Http::assertSent(fn (Request $request) => $request['request_id'] === 'request-1'
            && $request['slim'] === 'false');

        $log = YandexApiLog::latest('id')->firstOrFail();
        $this->assertSame('https://dostavka.yandex.ru/route/test-route', $log->response_body['sharing_url']);
        $this->assertSame('[REDACTED]', data_get($log->response_body, 'request.recipient_info.first_name'));
        $this->assertSame('[REDACTED]', data_get($log->response_body, 'request.recipient_info.phone'));
        $this->assertSame('[REDACTED]', data_get($log->response_body, 'request.recipient_info.email'));
    }
}
