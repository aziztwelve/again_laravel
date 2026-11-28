<?php

namespace Tests\Feature\Order;

use App\Jobs\PushOrderTracksToMoySkladJob;
use App\Jobs\SyncOrderToMoySkladJob;
use App\Models\CdekOrder;
use App\Models\Country;
use App\Models\Order;
use App\Services\Order\OrderUpdateService;
use App\Support\CountryName;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Страна в адресе хранится названием, а не кодом из форм админки; номер СДЭК уходит
 * в МойСклад одним треком, без полной выгрузки.
 */
class OrderCountryAndCdekNumberTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncOrderToMoySkladJob::class, PushOrderTracksToMoySkladJob::class]);
        Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия']);
        Country::firstOrCreate(['code' => 'KZ'], ['name' => 'Казахстан']);
    }

    private function order(?string $country): Order
    {
        $order = Order::create([
            'order_number' => 'CN-'.uniqid(), 'status' => 'new', 'payment_status' => 'pending',
            'total_amount' => 1000, 'moysklad_order_uuid' => 'ms-'.uniqid(),
        ]);
        $order->address()->create(['country' => $country, 'city' => 'Москва', 'address' => 'Тверская ул, 7']);

        return $order;
    }

    private function save(Order $order, string $country): string
    {
        $this->assertTrue(app(OrderUpdateService::class)->update($order, [
            'delivery_address' => ['country' => $country, 'city' => 'Москва', 'address' => 'Тверская ул, 7'],
        ]));

        return (string) $order->address()->value('country');
    }

    public function test_code_from_admin_form_keeps_the_stored_name_and_does_not_resync(): void
    {
        $this->assertSame('Россия', $this->save($this->order('Россия'), 'RU'));

        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_order_already_stored_with_code_is_not_rewritten_by_an_unrelated_save(): void
    {
        $this->assertSame('RU', $this->save($this->order('RU'), 'RU'));

        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_new_country_is_stored_by_name(): void
    {
        $this->assertSame('Казахстан', $this->save($this->order('Россия'), 'KZ'));
        $this->assertSame('Россия', $this->save($this->order(null), 'ru'));

        Bus::assertDispatched(SyncOrderToMoySkladJob::class);
    }

    public function test_names_and_unknown_values_stay_as_they_are(): void
    {
        $this->assertSame('Россия', CountryName::fromInput('Россия'));
        $this->assertSame('ZZ', CountryName::fromInput('ZZ'));
        $this->assertNull(CountryName::fromInput(null));
        $this->assertFalse(CountryName::same('Россия', 'Казахстан'));
    }

    public function test_cdek_number_sends_only_the_track(): void
    {
        $order = $this->order('Россия');
        $cdek = CdekOrder::create([
            'order_id' => $order->id, 'external_order_number' => 'EXT-'.uniqid(),
            'delivery_type' => 'pickup', 'tariff_code' => 136, 'currency' => 'RUB', 'creation_state' => 'ACCEPTED',
        ]);

        $cdek->update(['cdek_number' => '10337704825']);

        Bus::assertDispatched(PushOrderTracksToMoySkladJob::class, fn ($job) => $job->orderId === $order->id);
        Bus::assertNotDispatched(SyncOrderToMoySkladJob::class);
    }
}
