<?php

namespace App\Enums;

/**
 * Physical kind of a stock location.
 *
 * A store has at least one default location (see stock_locations.default_guard).
 * The type only describes what the place is; it does not change behaviour.
 *
 * - warehouse : back-of-house storage.
 * - outlet    : sales floor / point of sale.
 * - other     : anything else (kiosk, pop-up, etc.).
 */
enum StockLocationType: string
{
    case WAREHOUSE = 'warehouse';
    case OUTLET = 'outlet';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WAREHOUSE => 'Gudang',
            self::OUTLET => 'Toko / Outlet',
            self::OTHER => 'Lainnya',
        };
    }
}
