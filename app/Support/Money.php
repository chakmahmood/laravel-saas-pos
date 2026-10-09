<?php

namespace App\Support;

/**
 * Shared money bounds for server-side calculations.
 *
 * All monetary values are integer rupiah. The ceiling is below 2^53 (float
 * exactness) and far below PHP_INT_MAX / unsigned BIGINT, so additions and
 * multiplications cannot silently overflow.
 */
final class Money
{
    public const MAX = 9_000_000_000_000_000;

    public static function withinBounds(int $value): bool
    {
        return $value >= -self::MAX && $value <= self::MAX;
    }
}
