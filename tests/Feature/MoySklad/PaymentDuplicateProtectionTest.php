<?php

namespace Tests\Feature\MoySklad;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Services\MoySklad\CreationPendingException;
use App\Services\MoySklad\PaymentInService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Входящий платёж не должен создаваться дважды, когда ответ на создание не дошёл (заказ 41598). */
class PaymentDuplicateProtectionTest extends TestCase
{
    use DatabaseTransactions;

    /** Платежи, которые МойСклад отдаёт по externalCode. */
    private array $visible = [];

    /** Ответ на POST платежа: 'ok', 'timeout' или HTTP-код. */
    private string|int $create = 'ok';

    private int $lookupStatus = 200;

    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DeliveryServiceSetting::updateOrCreate(['service_name' => 'moysklad'], ['token' => 'test-token']);

        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/entity/paymentin?')) {
                $this->log[] = 'lookup';

                return Http::response(['rows' => $this->visible], $this->lookupStatus);
            }
            if ($method === 'POST' && str_ends_with($url, '/entity/paymentin')) {
                $this->log[] = 'create';
                if ($this->create === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return $this->create === 'ok'
                    ? Http::response(['id' => 'new-pay'])
                    : Http::response(['errors' => [['error' => 'fail']]], $this->create);
            }
            if (str_contains($url, '/entity/customerorder/metadata')) {
                return Http::response(['states' => [], 'rows' => []]);
            }
            if ($method === 'GET' && str_contains($url, '/entity/customerorder?')) {
                return Http::response(['rows' => []]);
            }

            return Http::response(['rows' => [['meta' => ['href' => 'x', 'type' => 'x']]], 'meta' => ['href' => 'x'], 'id' => 'x', 'sum' => 790000]);
        });
    }

    private function order(): Order
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::NEW,
            'payment_status' => PaymentStatus::PAID,
            'total_amount' => 7900,
            'moysklad_order_uuid' => 'ms-order',
            'moysklad_paymentin_uuid' => null,
        ]);
        Order::withoutEvents(fn () => $order->forceFill(['order_number' => (string) $order->id])->save());

        return $order->fresh();
    }

    private function payment(Order $order, string $id, string $msOrder = 'ms-order'): array
    {
        return [
            'id' => $id,
            'externalCode' => 'pay-'.$order->id,
            'operations' => [['meta' => ['href' => "https://api.moysklad.ru/api/remap/1.2/entity/customerorder/{$msOrder}", 'type' => 'customerorder']]],
        ];
    }

    private function register(Order $order): ?\Throwable
    {
        try {
            app(PaymentInService::class)->registerPayment($order->fresh());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function creations(): int
    {
        return count(array_keys($this->log, 'create', true));
    }

    public function test_first_payment_is_checked_then_created(): void
    {
        $order = $this->order();

        $this->assertNull($this->register($order));
        $this->assertSame(['lookup', 'create'], $this->log);
        $this->assertSame('new-pay', $order->fresh()->moysklad_paymentin_uuid);
    }

    public function test_lost_answer_does_not_create_a_second_payment(): void
    {
        $order = $this->order();

        $this->create = 'timeout';
        $this->assertInstanceOf(ConnectionException::class, $this->register($order));

        $this->create = 'ok';
        $this->assertInstanceOf(CreationPendingException::class, $this->register($order));
        $this->assertNull($order->fresh()->moysklad_paymentin_uuid);

        $this->visible = [$this->payment($order, 'first-pay')];
        $this->assertNull($this->register($order));
        $this->assertSame('first-pay', $order->fresh()->moysklad_paymentin_uuid);
        $this->assertSame(1, $this->creations());
    }

    public function test_creates_again_when_nothing_appeared_after_the_waiting_window(): void
    {
        $order = $this->order();
        $this->create = 'timeout';
        $this->register($order);

        $this->travel(181)->seconds();
        $this->create = 'ok';

        $this->assertNull($this->register($order));
        $this->assertSame(2, $this->creations());
    }

    public function test_server_error_waits_but_rejection_does_not(): void
    {
        $afterServerError = $this->order();
        $this->create = 503;
        $this->register($afterServerError);
        $this->create = 'ok';
        $this->assertInstanceOf(CreationPendingException::class, $this->register($afterServerError));

        $afterRejection = $this->order();
        $this->create = 412;
        $this->register($afterRejection);
        $this->create = 'ok';
        $this->assertNull($this->register($afterRejection));
        $this->assertSame('new-pay', $afterRejection->fresh()->moysklad_paymentin_uuid);
    }

    public function test_does_not_create_when_the_check_fails(): void
    {
        $order = $this->order();
        $this->lookupStatus = 503;

        $this->assertNotNull($this->register($order));
        $this->assertSame(0, $this->creations());
    }

    public function test_several_existing_payments_are_not_added_to(): void
    {
        $order = $this->order();
        $this->visible = [$this->payment($order, 'a'), $this->payment($order, 'b')];

        $this->assertNull($this->register($order));
        $this->assertSame('a', $order->fresh()->moysklad_paymentin_uuid);
        $this->assertSame(0, $this->creations());
    }

    public function test_payment_of_another_moysklad_order_is_not_adopted(): void
    {
        $order = $this->order();
        $this->visible = [$this->payment($order, 'foreign', 'other-order')];

        $this->assertNull($this->register($order));
        $this->assertSame('new-pay', $order->fresh()->moysklad_paymentin_uuid);
    }

    public function test_job_postpones_itself_while_payment_creation_is_pending(): void
    {
        $order = $this->order();
        $this->create = 'timeout';
        $this->register($order);

        $job = (new SyncOrderToMoySkladJob($order->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased();
        $this->assertSame(1, $this->creations());
    }
}
