<?php

namespace App\Enums;

/**
 * Primary business type of a store.
 *
 * A store has exactly one primary business type in the current phase. This
 * value gates which industry-specific modules are relevant to the store UI
 * and worker, without introducing dozens of boolean flags such as
 * `is_cafe`, `is_laundry`, or `is_repair`.
 *
 * The Universal POS foundation (categories, catalog items, customers, orders,
 * order items, payments, cash sessions, sales reports) is available to every
 * business type. Industry-specific tables and workflows are layered on top
 * only for the business types that need them.
 */
enum BusinessType: string
{
    case RETAIL = 'retail';
    case RESTAURANT = 'restaurant';
    case LAUNDRY = 'laundry';
    case REPAIR = 'repair';
    case SALON = 'salon';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RETAIL => 'Retail / Toko',
            self::RESTAURANT => 'Kafe & Restoran',
            self::LAUNDRY => 'Laundry',
            self::REPAIR => 'Servis & Reparasi',
            self::SALON => 'Salon & Barbershop',
            self::OTHER => 'Lainnya',
        };
    }

    /**
     * Whether this business type uses the retail inventory module.
     */
    public function usesInventory(): bool
    {
        return match ($this) {
            self::RETAIL, self::RESTAURANT => true,
            default => false,
        };
    }
}
