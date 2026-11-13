<?php

namespace Tests\Unit\Services\Delivery;

use App\Models\ShipmentStatus;
use App\Services\Delivery\Cdek\CdekStatus;
use PHPUnit\Framework\TestCase;

class CdekStatusTest extends TestCase
{
    public function test_rejected_request_is_a_failure_not_in_transit(): void
    {
        $this->assertTrue(CdekStatus::isFailure('INVALID'));
        $this->assertSame(ShipmentStatus::FAILED, CdekStatus::internal('INVALID'));
    }

    public function test_known_transit_statuses_stay_in_transit(): void
    {
        foreach (['ACCEPTED_AT_PICK_UP_POINT', 'SENT_TO_TRANSIT_CITY', 'READY_FOR_SHIPMENT_IN_TRANSIT_CITY', 'POSTOMAT_POSTED', 'TAKEN_BY_COURIER'] as $code) {
            $this->assertSame(ShipmentStatus::IN_TRANSIT, CdekStatus::internal($code), $code);
        }
    }

    public function test_milestone_statuses(): void
    {
        $this->assertSame(ShipmentStatus::NEW, CdekStatus::internal('CREATED'));
        $this->assertSame(ShipmentStatus::NEW, CdekStatus::internal('ACCEPTED'));
        $this->assertSame(ShipmentStatus::DELIVERED, CdekStatus::internal('DELIVERED'));
        $this->assertSame(ShipmentStatus::RETURNED, CdekStatus::internal('NOT_DELIVERED'));
        $this->assertSame(ShipmentStatus::CANCELLED, CdekStatus::internal('REMOVED'));
    }

    public function test_unknown_status_is_never_treated_as_in_transit(): void
    {
        $this->assertFalse(CdekStatus::isKnown('SOMETHING_NEW_FROM_CDEK'));
        $this->assertSame(ShipmentStatus::PROCESSING, CdekStatus::internal('SOMETHING_NEW_FROM_CDEK'));
    }

    public function test_codes_are_case_insensitive(): void
    {
        $this->assertSame(ShipmentStatus::FAILED, CdekStatus::internal('invalid'));
    }
}
