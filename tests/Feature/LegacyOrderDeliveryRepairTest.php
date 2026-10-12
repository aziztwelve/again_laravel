<?php

namespace Tests\Feature;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Services\Order\OrderCustomFieldsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyOrderDeliveryRepairTest extends TestCase
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

    private function source(Order $order, string $delivery, string $cdek = 'CDEK-123', string $post = 'POST-456'): string
    {
        $this->csvPath = sys_get_temp_dir().'/delivery-repair-'.Str::uuid().'.csv';
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, ['№', 'Способ доставки', 'Трек-номер', 'Номер для отслеживания заказа СДЭК', 'Трек-номер Почта России', 'Наименование товара (услуги)'], "\t");
        fputcsv($handle, [$order->order_number, $delivery, '', $cdek, $post, 'Товар'], "\t");
        fclose($handle);

        return $this->csvPath;
    }

    private function method(string $name): DeliveryMethod
    {
        return DeliveryMethod::create(['name' => $name, 'code' => 'test-'.Str::uuid(), 'is_active' => true]);
    }

    public function test_post_field_reads_import_alias_and_prefers_canonical_value(): void
    {
        $service = new OrderCustomFieldsService();
        $order = new Order(['legacy_meta' => ['russian_post_track' => 'IMPORT-123']]);
        $this->assertSame('IMPORT-123', collect($service->forOrder($order))->keyBy('key')['russian_post_tracking_number']['value']);
        $order->legacy_meta = ['russian_post_track' => 'IMPORT-123', 'russian_post_tracking_number' => 'NEW-456'];
        $fields = collect($service->forOrder($order))->keyBy('key');
        $this->assertSame('NEW-456', $fields['russian_post_tracking_number']['value']);
        $this->assertSame('Трек-номер', $fields['tracking_number']['label']);
    }

    public function test_clearing_post_field_does_not_resurrect_import_alias(): void
    {
        $order = Order::factory()->create(['legacy_meta' => ['russian_post_track' => 'OLD-123', 'transaction_id' => 'keep']]);
        $service = new OrderCustomFieldsService();
        $service->update($order, ['russian_post_tracking_number' => 'NEW-456']);
        $this->assertSame('NEW-456', $order->fresh()->legacy_meta['russian_post_tracking_number']);
        $this->assertArrayNotHasKey('russian_post_track', $order->fresh()->legacy_meta);
        $service->update($order, ['russian_post_tracking_number' => '']);
        $fresh = $order->fresh();
        $this->assertNull(collect($service->forOrder($fresh))->keyBy('key')['russian_post_tracking_number']['value']);
        $this->assertSame('keep', $fresh->legacy_meta['transaction_id']);
    }

    public function test_repair_fills_separate_tracks_and_reconciles_name_without_overwriting_general_track(): void
    {
        $method = $this->method('Repair pickup');
        $order = Order::factory()->create([
            'delivery_method_id' => $method->id, 'legacy_delivery_method' => 'Old delivery',
            'tracking_number' => 'CURRENT-999', 'legacy_meta' => ['transaction_id' => 'keep'],
        ]);
        $path = $this->source($order, 'Repair pickup (legacy module)');
        $this->artisan('sync:legacy-order-delivery', ['file' => $path, '--reconcile-delivery-names' => true])->assertExitCode(0);
        $fresh = $order->fresh();
        $this->assertSame('CURRENT-999', $fresh->tracking_number);
        $this->assertSame('CDEK-123', $fresh->legacy_meta['cdek_track_number']);
        $this->assertSame('POST-456', $fresh->legacy_meta['russian_post_track']);
        $this->assertSame('keep', $fresh->legacy_meta['transaction_id']);
        $this->assertSame('Repair pickup (legacy module)', $fresh->legacy_delivery_method);
        $before = $fresh->getRawOriginal();
        $this->artisan('sync:legacy-order-delivery', ['file' => $path, '--reconcile-delivery-names' => true])->assertExitCode(0);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_repair_preserves_current_special_tracks_and_delivery_changes(): void
    {
        $method = $this->method('New carrier');
        $sourceMethod = $this->method('Old carrier');
        $order = Order::factory()->create([
            'delivery_method_id' => $method->id, 'legacy_delivery_method' => 'New carrier',
            'legacy_meta' => ['cdek_track_number' => 'CURRENT-CDEK', 'russian_post_tracking_number' => 'CURRENT-POST'],
        ]);
        $path = $this->source($order, $sourceMethod->name);
        $this->artisan('sync:legacy-order-delivery', ['file' => $path, '--reconcile-delivery-names' => true])->assertExitCode(0);
        $fresh = $order->fresh();
        $this->assertSame('CURRENT-CDEK', $fresh->legacy_meta['cdek_track_number']);
        $this->assertSame('CURRENT-POST', $fresh->legacy_meta['russian_post_tracking_number']);
        $this->assertArrayNotHasKey('russian_post_track', $fresh->legacy_meta);
        $this->assertSame('New carrier', $fresh->legacy_delivery_method);
        $this->assertSame($method->id, $fresh->delivery_method_id);
    }

    public function test_dry_run_does_not_modify_orders(): void
    {
        $order = Order::factory()->create(['legacy_meta' => ['transaction_id' => 'keep']]);
        $before = $order->fresh()->getRawOriginal();
        $path = $this->source($order, 'Unknown carrier');
        $this->artisan('sync:legacy-order-delivery', ['file' => $path, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }
}
