<?php

namespace App\Enums;

/**
 * Order payment status, derived server-side from valid payments and the order
 * total. It is never taken from client input.
 *
 * `REFUNDED` is reserved for a future, complete refund flow. No Phase 2
 * endpoint sets it, and it is documented as not yet reachable.
 */
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Belum dibayar',
            self::PARTIALLY_PAID => 'Dibayar sebagian',
            self::PAID => 'Lunas',
            self::REFUNDED => 'Dikembalikan',
        };
    }
}
