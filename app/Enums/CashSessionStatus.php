<?php

namespace App\Enums;

/**
 * Lifecycle of a cashier shift (cash session).
 *
 * A shift is opened by a cashier with an opening cash float, accumulates cash
 * movements and valid cash payments, then is closed with a physical cash count.
 */
enum CashSessionStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::CLOSED => 'Ditutup',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }
}
