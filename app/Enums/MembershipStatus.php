<?php

namespace App\Enums;

/**
 * Lifecycle status of a store membership.
 *
 * - active   : the member can access the store.
 * - inactive : access is temporarily disabled and can be re-enabled.
 *
 * The status is derived from `store_user.is_active`; it is not a separate
 * stored column. Membership rows are never physically deleted so history is
 * preserved.
 */
enum MembershipStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::INACTIVE => 'Nonaktif',
        };
    }
}
