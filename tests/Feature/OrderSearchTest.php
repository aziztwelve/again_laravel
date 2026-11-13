<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Services\Order\OrderFilterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderSearchTest extends TestCase
{
    use DatabaseTransactions;

    private function order(array $addr = [], array $order = []): Order
    {
        $o = Order::factory()->create($order);
        OrderAddress::create(array_merge([
            'order_id' => $o->id,
            'recipient_first_name' => 'Анна',
            'recipient_last_name' => 'Петрова',
            'city' => 'Москва',
            'address' => 'ул. Тверская, 1',
        ], $addr));

        return $o;
    }

    /** @return list<int> id заказов из $scope в порядке выдачи (с сортировкой по умолчанию) */
    private function found(string $search, array $scope): array
    {
        $request = Request::create('/orders', 'GET', ['search' => $search]);
        $service = app(OrderFilterService::class);
        $query = $service->applyFilters(Order::query()->whereIn('id', $scope), $request);

        return $service->applySorting($query, $request)->pluck('id')->all();
    }

    public function test_surname_and_name_in_any_order_are_found(): void
    {
        $kalinina = $this->order(['recipient_last_name' => 'Калинина', 'recipient_first_name' => 'Елена']);
        $other = $this->order(['recipient_last_name' => 'Калинин', 'recipient_first_name' => 'Игорь']);
        $scope = [$kalinina->id, $other->id];

        $this->assertSame([$kalinina->id], $this->found('Калинина Елена', $scope));
        $this->assertSame([$kalinina->id], $this->found('елена калинина', $scope));
    }

    public function test_city_and_street_words_in_any_order_are_found(): void
    {
        $kazan = $this->order(['city' => 'Казань', 'address' => 'Казань улица Калинина 52']);
        $korolev = $this->order(['city' => 'Королев', 'address' => 'ул. Калинина, 6Б']);
        $scope = [$kazan->id, $korolev->id];

        $this->assertSame([$kazan->id], $this->found('Калинина Казань', $scope));
        $this->assertSame([$korolev->id], $this->found('Королев Калинина', $scope));
    }

    public function test_recipient_surname_goes_before_street_with_same_word(): void
    {
        $street = $this->order(['recipient_last_name' => 'Иванова', 'address' => 'ул. Калинина, 6'], ['created_at' => now()]);
        $person = $this->order(['recipient_last_name' => 'Калинина'], ['created_at' => now()->subDays(30)]);

        $this->assertSame([$person->id, $street->id], $this->found('Калинина', [$street->id, $person->id]));
    }

    public function test_guest_email_is_searchable(): void
    {
        $guest = $this->order([], ['email' => 'guest.buyer@example.org', 'client_id' => null]);
        $other = $this->order([], ['email' => 'someone@example.org', 'client_id' => null]);

        $this->assertSame([$guest->id], $this->found('guest.buyer', [$guest->id, $other->id]));
    }

    public function test_every_word_must_match(): void
    {
        $o = $this->order(['recipient_last_name' => 'Калинина', 'city' => 'Москва']);

        $this->assertSame([], $this->found('Калинина Новосибирск', [$o->id]));
    }

    public function test_order_number_search_still_works(): void
    {
        $o = $this->order([], ['order_number' => '99887766']);
        $other = $this->order([], ['order_number' => '99887755']);

        $this->assertSame([$o->id], $this->found('№99887766', [$o->id, $other->id]));
    }

    public function test_percent_sign_is_not_a_wildcard(): void
    {
        $o = $this->order();

        $this->assertSame([], $this->found('%', [$o->id]));
    }

    public function test_phone_search_finds_recipient_phone_only(): void
    {
        $o = $this->order(['recipient_phone' => '+7 (999) 123-45-67']);
        $other = $this->order(['recipient_phone' => '+7 (911) 000-00-00']);

        $this->assertSame([$o->id], $this->found('+7 999 123', [$o->id, $other->id]));
        $this->assertSame([$o->id], $this->found('89991234567', [$o->id, $other->id]));
    }
}
