<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Import\OrderImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderImportTrackPlacementTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $csvPath = null;

    protected function tearDown(): void
    {
        if ($this->csvPath !== null) {
            @unlink($this->csvPath);
        }
        parent::tearDown();
    }

    /**
     * Строит одну строку заказа InSales (табуляция, UTF-8) с заданными трек-полями.
     */
    private function source(
        string $number,
        string $track = '',
        string $post = '',
        string $cdek = '',
        string $delivery = 'СДЭК: Курьерская доставка (Официальный модуль доставки от компании СДЭК)',
    ): string {
        $this->csvPath = sys_get_temp_dir().'/track-placement-'.Str::uuid().'.csv';
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, [
            '№', 'Дата создания', 'Статус заказа', 'Статус оплаты', 'Способ доставки',
            'Сумма для получения', 'Наименование товара (услуги)', 'Количество',
            'Трек-номер', 'Трек-номер Почта России', 'Номер для отслеживания заказа СДЭК',
        ], "\t");
        fputcsv($handle, [
            $number, '02.09.2026 10:00', 'Новый', 'Не оплачен', $delivery,
            '1000', 'Товар', '1', $track, $post, $cdek,
        ], "\t");
        fclose($handle);

        return $this->csvPath;
    }

    public function test_yandex_track_goes_to_tracking_number_and_cdek_number_to_legacy_meta(): void
    {
        $path = $this->source('990001', track: 'YA689C2EC71F2C84F59', cdek: '10315500001');

        app(OrderImportService::class)->import($path);

        $order = Order::where('order_number', '990001')->firstOrFail();
        $this->assertSame('YA689C2EC71F2C84F59', $order->tracking_number);
        $this->assertSame('10315500001', $order->legacy_meta['cdek_track_number']);
    }

    public function test_post_track_fills_tracking_number_when_main_track_is_empty(): void
    {
        $path = $this->source('990002', post: '80203024809499');

        app(OrderImportService::class)->import($path);

        $order = Order::where('order_number', '990002')->firstOrFail();
        $this->assertSame('80203024809499', $order->tracking_number);
    }

    public function test_cdek_number_fills_tracking_number_only_when_nothing_else_is_present(): void
    {
        $path = $this->source('990003', cdek: '10315500003');

        app(OrderImportService::class)->import($path);

        $order = Order::where('order_number', '990003')->firstOrFail();
        $this->assertSame('10315500003', $order->tracking_number);
        $this->assertSame('10315500003', $order->legacy_meta['cdek_track_number']);
    }

    public function test_cdek_number_does_not_become_tracking_for_non_cdek_service(): void
    {
        $path = $this->source('990005', cdek: '10315500005', delivery: 'Пункт самовывоза Boxberry (Доставка на пункт самовывоза Boxberry)');

        app(OrderImportService::class)->import($path);

        $order = Order::where('order_number', '990005')->firstOrFail();
        $this->assertNull($order->tracking_number);
        $this->assertSame('10315500005', $order->legacy_meta['cdek_track_number']);
    }

    public function test_no_overwrite_keeps_existing_order_tracking_untouched(): void
    {
        Order::factory()->create([
            'order_number' => '990004',
            'tracking_number' => 'OLD-TRACK',
        ]);
        $path = $this->source('990004', track: 'NEW-TRACK', cdek: '10315500004');

        app(OrderImportService::class)->import($path, ['overwrite' => false]);

        $this->assertSame('OLD-TRACK', Order::where('order_number', '990004')->firstOrFail()->tracking_number);
    }
}
