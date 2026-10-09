<?php

namespace App\Enums;

/**
 * Manual payment method. No payment gateway is integrated in this phase;
 * every value is recorded manually by staff.
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case CARD = 'card';
    case E_WALLET = 'e_wallet';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Tunai',
            self::BANK_TRANSFER => 'Transfer Bank',
            self::CARD => 'Kartu',
            self::E_WALLET => 'E-Wallet',
            self::OTHER => 'Lainnya',
        };
    }
}
