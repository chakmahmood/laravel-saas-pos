<?php

namespace App\Enums;

/**
 * Status of an individual payment record.
 *
 * Recorded payments are never deleted to fix a mistake; they are voided so the
 * audit trail stays intact. Only `COMPLETED` payments count toward an order's
 * paid amount.
 */
enum PaymentRecordStatus: string
{
    case COMPLETED = 'completed';
    case VOIDED = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => 'Tercatat',
            self::VOIDED => 'Dibatalkan',
        };
    }

    public function isActive(): bool
    {
        return $this === self::COMPLETED;
    }
}
