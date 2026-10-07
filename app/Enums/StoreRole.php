<?php

namespace App\Enums;

enum StoreRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case CASHIER = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Admin',
            self::CASHIER => 'Kasir',
        };
    }
}
