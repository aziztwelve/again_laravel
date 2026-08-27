<?php

namespace Tests\Feature\MoySklad;

use App\Jobs\ReturnOrderStockToMoySkladJob;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use App\Services\MoySklad\MoySkladSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Фоновая синхронизация с МойСклад не должна падать, если интеграция не
 * настроена: конструкторы сервисов бросают исключение, из-за чего задача
 * уходила в 3 retry и попадала в failed_jobs, а при sync-очереди ломала
 * ответ публичного чекаута (201 превращался в 500 по уже созданному заказу).
 */
class MoySkladNotConfiguredTest extends TestCase
{
    use DatabaseTransactions;

    public function test_settings_helper_reports_missing_integration(): void
    {
        DeliveryServiceSetting::query()->where('service_name', 'moysklad')->delete();

        self::assertFalse(MoySkladSettings::isConfigured());
        self::assertNull(MoySkladSettings::token());
    }

    public function test_settings_helper_reports_configured_integration(): void
    {
        DeliveryServiceSetting::query()->where('service_name', 'moysklad')->delete();
        DeliveryServiceSetting::create([
            'service_name' => 'moysklad',
            'token' => 'test-token',
        ]);

        self::assertTrue(MoySkladSettings::isConfigured());
        self::assertSame('test-token', MoySkladSettings::token());
    }

    public function test_sync_job_skips_without_settings_instead_of_failing(): void
    {
        DeliveryServiceSetting::query()->where('service_name', 'moysklad')->delete();

        $order = $this->order();

        Log::shouldReceive('warning')
            ->once()
            ->withSomeOfArgs('SyncOrderToMoySkladJob: МойСклад не настроен, синхронизация пропущена');

        // Задача не должна бросать: иначе retry + failed_jobs.
        (new SyncOrderToMoySkladJob($order->id))->handle();
    }

    public function test_return_stock_job_skips_without_settings_instead_of_failing(): void
    {
        DeliveryServiceSetting::query()->where('service_name', 'moysklad')->delete();

        $order = $this->order();

        Log::shouldReceive('warning')
            ->once()
            ->withSomeOfArgs('ReturnOrderStockToMoySkladJob: МойСклад не настроен, возврат пропущен');

        (new ReturnOrderStockToMoySkladJob($order->id))->handle();
    }

    private function order(): Order
    {
        return Order::create([
            'status' => 'new',
            'payment_status' => 'pending',
            'total_amount' => 1000,
        ]);
    }
}
