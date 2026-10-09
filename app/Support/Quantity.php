<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact stock quantity arithmetic in integer milli-units.
 *
 * Stock quantities are decimal(12,3). Doing the arithmetic in floating point
 * risks drift (e.g. 0.1 + 0.2), so every value is converted to integer
 * thousandths ("millis") and back. The largest representable quantity,
 * 999_999_999.999, is 999_999_999_999 millis, far below PHP_INT_MAX, so the
 * conversion is always exact.
 *
 * Values are non-negative on the way in; `fromMillis` still formats a negative
 * result defensively, but the ledger never stores negative quantities.
 */
final class Quantity
{
    public const SCALE = 3;

    private const MILLIS_PER_UNIT = 1000;

    public static function toMillis(int|float|string $value): int
    {
        $string = self::normalize($value);

        if (! preg_match('/^\d+(\.\d{1,3})?$/', $string)) {
            throw new InvalidArgumentException("Invalid stock quantity [{$string}].");
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $fraction = str_pad($fraction, self::SCALE, '0');

        return ((int) $whole) * self::MILLIS_PER_UNIT + (int) $fraction;
    }

    public static function fromMillis(int $millis): string
    {
        $sign = $millis < 0 ? '-' : '';
        $millis = abs($millis);

        return sprintf(
            '%s%d.%03d',
            $sign,
            intdiv($millis, self::MILLIS_PER_UNIT),
            $millis % self::MILLIS_PER_UNIT,
        );
    }

    private static function normalize(int|float|string $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }
}
