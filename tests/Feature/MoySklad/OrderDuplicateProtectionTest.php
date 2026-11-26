<?php

namespace Tests\Feature\MoySklad;

use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Services\MoySklad\OrderCreationPendingException;
use App\Services\MoySklad\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Заказ не должен попадать в МойСклад дважды, когда ответ на создание не дошёл (заказ 41832). */
class OrderDuplicateProtectionTest extends TestCase
{
    use DatabaseTransactions;

    /** Документы, которые «видит» МойСклад в списке по номеру. */
    private array $visible = [];

    /** Ответ на POST: 'ok', 'timeout' или HTTP-код. */
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

            if (str_contains($url, '/entity/customerorder/metadata')) {
                return Http::response(['states' => [], 'rows' => []]);
            }
            if ($method === 'GET' && str_contains($url, '/entity/customerorder?')) {
                $this->log[] = 'lookup';

                return Http::response(['rows' => $this->visible], $this->lookupStatus);
            }
            if ($method === 'POST' && str_ends_with($url, '/entity/customerorder')) {
                $this->log[] = 'create';
                if ($this->create === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return $this->create === 'ok'
                    ? Http::response(['id' => 'new-doc'])
                    : Http::response(['errors' => [['error' => 'fail']]], $this->create);
            }
            if ($method === 'PUT' && str_contains($url, '/entity/customerorder/')) {
                $this->log[] = 'update:'.basename($url);

                return Http::response(['id' => basename($url)]);
            }

            return Http::response(['rows' => [['meta' => ['href' => 'x', 'type' => 'x']]], 'meta' => ['href' => 'x'], 'id' => 'x']);
        });
    }

    private function order(array $attributes = []): Order
    {
        $order = Order::factory()->create($attributes + ['moysklad_order_uuid' => null]);
        Order::withoutEvents(fn () => $order->forceFill(['order_number' => (string) $order->id])->save());

        return $order->fresh();
    }

    private function doc(Order $order, string $id, ?string $externalCode = null): array
    {
        return ['id' => $id, 'name' => (string) $order->order_number, 'externalCode' => $externalCode ?? (string) $order->id];
    }

    private function push(Order $order): ?\Throwable
    {
        try {
            app(OrderService::class)->pushOrder($order->fresh());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function creations(): int
    {
        return count(array_keys($this->log, 'create', true));
    }

    public function test_first_export_checks_then_creates_the_order(): void
    {
        $order = $this->order();

        $this->assertNull($this->push($order));
        $this->assertSame(['lookup', 'create'], $this->log);
        $this->assertSame('new-doc', $order->fresh()->moysklad_order_uuid);
    }

    public function test_lost_answer_does_not_create_a_second_document(): void
    {
        $order = $this->order();

        // Ответ на создание не дошёл.
        $this->create = 'timeout';
        $this->assertInstanceOf(ConnectionException::class, $this->push($order));

        // Сразу следом (оплата): МойСклад документ ещё не показывает — ждём, не создаём.
        $this->create = 'ok';
        $pending = $this->push($order);
        $this->assertInstanceOf(OrderCreationPendingException::class, $pending);
        $this->assertGreaterThan(0, $pending->retryAfter);
        $this->assertNull($order->fresh()->moysklad_order_uuid);

        // Документ появился: связываем и обновляем его.
        $this->visible = [$this->doc($order, 'first-doc')];
        $this->assertNull($this->push($order));
        $this->assertSame('first-doc', $order->fresh()->moysklad_order_uuid);
        $this->assertSame(1, $this->creations());
        $this->assertContains('update:first-doc', $this->log);
    }

    public function test_creates_again_when_nothing_appeared_after_the_waiting_window(): void
    {
        $order = $this->order();
        $this->create = 'timeout';
        $this->push($order);

        $this->travel(181)->seconds();
        $this->create = 'ok';

        $this->assertNull($this->push($order));
        $this->assertSame(2, $this->creations());
        $this->assertSame('new-doc', $order->fresh()->moysklad_order_uuid);
    }

    public function test_server_error_waits_but_rejection_does_not(): void
    {
        $afterServerError = $this->order();
        $this->create = 503;
        $this->push($afterServerError);
        $this->create = 'ok';
        $this->assertInstanceOf(OrderCreationPendingException::class, $this->push($afterServerError));

        $afterRejection = $this->order();
        $this->create = 412;
        $this->push($afterRejection);
        $this->create = 'ok';
        $this->assertNull($this->push($afterRejection));
        $this->assertSame('new-doc', $afterRejection->fresh()->moysklad_order_uuid);
    }

    public function test_does_not_create_when_the_check_fails_or_several_documents_exist(): void
    {
        $order = $this->order();

        $this->lookupStatus = 503;
        $this->assertNotNull($this->push($order));

        $this->lookupStatus = 200;
        $this->visible = [$this->doc($order, 'a'), $this->doc($order, 'b')];
        $this->assertStringContainsString('несколько раз', $this->push($order)->getMessage());

        $this->assertSame(0, $this->creations());
        $this->assertNull($order->fresh()->moysklad_order_uuid);
    }

    public function test_document_of_another_order_with_the_same_number_is_not_adopted(): void
    {
        $order = $this->order();
        $this->visible = [$this->doc($order, 'foreign', 'old-insales-1')];

        $this->assertNull($this->push($order));
        $this->assertSame('new-doc', $order->fresh()->moysklad_order_uuid);
    }

    public function test_linked_order_is_updated_without_a_check(): void
    {
        $order = $this->order(['moysklad_order_uuid' => 'linked-doc']);

        $this->assertNull($this->push($order));
        $this->assertSame(['update:linked-doc'], $this->log);
    }

    public function test_job_postpones_itself_instead_of_failing_while_creation_is_pending(): void
    {
        $order = $this->order();
        $this->create = 'timeout';
        $this->push($order);

        $job = (new SyncOrderToMoySkladJob($order->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased();
        $this->assertSame(1, $this->creations());
        $this->assertSame(5, $job->tries);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
    }
}
