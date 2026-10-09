<?php

namespace Tests\Unit;

use App\Enums\StockMovementType;
use Tests\TestCase;

/**
 * The movement direction mapping is the contract the ledger service will rely
 * on, so it is locked down here before the service exists.
 */
class StockMovementTypeTest extends TestCase
{
    public function test_inbound_types_increase_on_hand(): void
    {
        foreach ([
            StockMovementType::OPENING,
            StockMovementType::PURCHASE_IN,
            StockMovementType::ADJUSTMENT_IN,
            StockMovementType::TRANSFER_IN,
            StockMovementType::RETURN_IN,
        ] as $type) {
            $this->assertTrue($type->increasesOnHand(), $type->value.' should increase on-hand');
            $this->assertFalse($type->decreasesOnHand(), $type->value.' should not decrease on-hand');
        }
    }

    public function test_outbound_types_decrease_on_hand(): void
    {
        foreach ([
            StockMovementType::SALE_OUT,
            StockMovementType::ADJUSTMENT_OUT,
            StockMovementType::TRANSFER_OUT,
            StockMovementType::RETURN_OUT,
        ] as $type) {
            $this->assertTrue($type->decreasesOnHand(), $type->value.' should decrease on-hand');
            $this->assertFalse($type->increasesOnHand(), $type->value.' should not increase on-hand');
        }
    }

    public function test_reservation_types_affect_only_reserved_quantity(): void
    {
        $this->assertTrue(StockMovementType::RESERVATION->increasesReserved());
        $this->assertFalse(StockMovementType::RESERVATION->decreasesReserved());
        $this->assertFalse(StockMovementType::RESERVATION->increasesOnHand());
        $this->assertFalse(StockMovementType::RESERVATION->decreasesOnHand());

        $this->assertTrue(StockMovementType::RESERVATION_RELEASE->decreasesReserved());
        $this->assertFalse(StockMovementType::RESERVATION_RELEASE->increasesReserved());
        $this->assertFalse(StockMovementType::RESERVATION_RELEASE->increasesOnHand());
        $this->assertFalse(StockMovementType::RESERVATION_RELEASE->decreasesOnHand());
    }

    public function test_reversal_has_no_fixed_direction(): void
    {
        $reversal = StockMovementType::REVERSAL;

        $this->assertTrue($reversal->isReversal());
        $this->assertFalse($reversal->increasesOnHand());
        $this->assertFalse($reversal->decreasesOnHand());
        $this->assertFalse($reversal->increasesReserved());
        $this->assertFalse($reversal->decreasesReserved());
    }

    public function test_every_non_reversal_type_has_exactly_one_direction(): void
    {
        foreach (StockMovementType::cases() as $type) {
            $directions = array_filter([
                $type->increasesOnHand(),
                $type->decreasesOnHand(),
                $type->increasesReserved(),
                $type->decreasesReserved(),
            ]);

            if ($type === StockMovementType::REVERSAL) {
                $this->assertCount(0, $directions, 'reversal must not declare a fixed direction');

                continue;
            }

            $this->assertCount(1, $directions, $type->value.' must declare exactly one direction');
        }
    }
}
