<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\Cdek\CdekParcel;
use PHPUnit\Framework\TestCase;

class CdekParcelTest extends TestCase
{
    private function measure(array $item): array
    {
        return CdekParcel::measure($item, ['weight' => 80, 'length' => 26, 'width' => 21, 'height' => 4]);
    }

    public function test_zero_decimal_string_falls_back_to_the_next_value(): void
    {
        // Вариант 0.000 (строка из decimal-каста) не должен перекрывать вес товара.
        $this->assertSame(0.2, CdekParcel::firstPositive('0.000', '0.200'));
        $this->assertNull(CdekParcel::firstPositive('0.000', null, 0));
    }

    public function test_weight_is_converted_to_grams(): void
    {
        $this->assertSame(200, CdekParcel::grams('0.200'), 'введён в кг');
        $this->assertSame(117, CdekParcel::grams('117.000'), 'уже в граммах (МойСклад)');
        $this->assertNull(CdekParcel::grams('0.000'));
        $this->assertNull(CdekParcel::grams(null));
    }

    public function test_missing_weight_uses_default_package_weight(): void
    {
        $this->assertSame(80, $this->measure(['weight' => '0.000'])['weight']);
    }

    public function test_dimensions_are_rounded_up(): void
    {
        $this->assertSame(18, $this->measure(['length' => 20, 'width' => 3, 'height' => '17.5'])['height']);
    }

    public function test_single_item_keeps_its_own_box(): void
    {
        $box = CdekParcel::dimensions([$this->measure(['length' => 27, 'width' => 5, 'height' => 24, 'quantity' => 1])]);

        $this->assertSame(['length' => 27, 'width' => 24, 'height' => 5], $box);
    }

    public function test_order_40747_no_longer_becomes_a_162_cm_tower(): void
    {
        $items = [
            ['length' => 27, 'width' => 5, 'height' => 24, 'quantity' => 2],
            ['length' => 20, 'width' => 3, 'height' => 17.5, 'quantity' => 1],
            ['length' => 20, 'width' => 3, 'height' => 17.5, 'quantity' => 1],
            ['length' => 10, 'width' => 6.5, 'height' => 20, 'quantity' => 2],
            ['length' => 10, 'width' => 6.5, 'height' => 20, 'quantity' => 2],
        ];

        $box = CdekParcel::dimensions(array_map(fn (array $item) => $this->measure($item), $items));

        $this->assertLessThanOrEqual(40, $box['length']);
        $this->assertLessThanOrEqual(40, $box['width']);
        $this->assertLessThanOrEqual(40, $box['height']);
        // Объём коробки вмещает объём всех позиций.
        $volume = 2 * 27 * 5 * 24 + 2 * 20 * 3 * 18 + 4 * 10 * 7 * 20; // комплекты, трусы, 4 геля
        $this->assertGreaterThanOrEqual($volume, $box['length'] * $box['width'] * $box['height']);
        // Постамат МСК1069 принимает ячейку 64×36×40.
        $this->assertNull(CdekParcel::postamatFitError(
            ['type' => 'POSTAMAT', 'weight_max' => 30, 'dimensions' => [['width' => 64, 'height' => 36, 'depth' => 40]]],
            2000,
            $box,
        ));
    }

    public function test_every_item_fits_the_box_in_some_orientation(): void
    {
        $box = CdekParcel::dimensions([
            $this->measure(['length' => 60, 'width' => 4, 'height' => 4, 'quantity' => 1]),
            $this->measure(['length' => 10, 'width' => 10, 'height' => 10, 'quantity' => 3]),
        ]);

        $this->assertGreaterThanOrEqual(60, $box['length']);
        $this->assertGreaterThanOrEqual(10, $box['width']);
    }

    public function test_parcel_that_exceeds_every_cell_is_rejected_with_a_message(): void
    {
        $error = CdekParcel::postamatFitError(
            ['type' => 'POSTAMAT', 'dimensions' => [['width' => 64, 'height' => 36, 'depth' => 40]]],
            1000,
            ['length' => 162, 'width' => 27, 'height' => 6],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('не помещается', $error);
    }

    public function test_overweight_parcel_is_rejected(): void
    {
        $error = CdekParcel::postamatFitError(['type' => 'POSTAMAT', 'weight_max' => 5, 'dimensions' => []], 8000, ['length' => 10, 'width' => 10, 'height' => 10]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Вес посылки', $error);
    }

    public function test_regular_pickup_points_are_not_checked_by_cells(): void
    {
        $this->assertNull(CdekParcel::postamatFitError(
            ['type' => 'PVZ', 'dimensions' => [['width' => 1, 'height' => 1, 'depth' => 1]]],
            1000,
            ['length' => 100, 'width' => 100, 'height' => 100],
        ));
    }
}
