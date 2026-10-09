<?php

namespace App\Enums;

/**
 * Universal catalog item type.
 *
 * A single `items` table serves every business type. The type only describes
 * what the item represents; it does not change the table shape.
 *
 * - product : physical goods (rice, clothes, cosmetics).
 * - service : labour / jobs (motor service, oil change, haircut).
 * - menu    : food and beverages (iced coffee, fried rice).
 * - package : a bundle sold as one item (complete wash package).
 */
enum ItemType: string
{
    case PRODUCT = 'product';
    case SERVICE = 'service';
    case MENU = 'menu';
    case PACKAGE = 'package';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'Produk',
            self::SERVICE => 'Jasa',
            self::MENU => 'Menu',
            self::PACKAGE => 'Paket',
        };
    }
}
