<?php

namespace App\Enums;

/**
 * Manual cash movement type inside a shift.
 *
 * - cash_in  : cash added to the drawer (e.g. owner top-up, change fund).
 * - cash_out : cash taken from the drawer (e.g. petty cash, supplier payment).
 */
enum CashMovementType: string
{
    case CASH_IN = 'cash_in';
    case CASH_OUT = 'cash_out';

    public function label(): string
    {
        return match ($this) {
            self::CASH_IN => 'Kas Masuk',
            self::CASH_OUT => 'Kas Keluar',
        };
    }
}
