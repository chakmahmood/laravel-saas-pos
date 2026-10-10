<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Canonical primary business group of a store.
 *
 * There are exactly TWO canonical values:
 * - `retail`  → "Toko & Penjualan" (warung, toko, kafe, restoran, penjualan
 *               produk/menu). Uses the inventory module.
 * - `service` → "Jasa & Servis" (laundry, bengkel, salon, reparasi, layanan).
 *               Does not use the inventory module.
 *
 * F&B (kafe/restoran) is NOT a separate business type; it is `retail`.
 * Laundry/workshop/salon are NOT separate business types; they are `service`
 * workflow templates handled at a later checkpoint (see docs/business-types.md).
 *
 * ## Backward compatibility
 *
 * Stores created before this refactor may still hold legacy values in the
 * `stores.business_type` column (`restaurant`, `laundry`, `repair`, `salon`,
 * `other`). {@see self::canonicalize()} maps them to a canonical group so the
 * application keeps working while the data is normalized:
 *
 * - retail, restaurant             → retail
 * - laundry, repair, salon, other  → service
 *
 * The enum itself never exposes the legacy values; only the mapping does.
 */
enum BusinessType: string
{
    case RETAIL = 'retail';
    case SERVICE = 'service';

    /**
     * Legacy `stores.business_type` values mapped to a canonical group.
     *
     * @var array<string, string>
     */
    private const LEGACY_ALIASES = [
        'retail' => 'retail',
        'restaurant' => 'retail',
        'laundry' => 'service',
        'repair' => 'service',
        'salon' => 'service',
        'other' => 'service',
    ];

    public function label(): string
    {
        return match ($this) {
            self::RETAIL => 'Toko & Penjualan',
            self::SERVICE => 'Jasa & Servis',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RETAIL => 'Untuk warung, toko, kafe, restoran, dan usaha penjualan produk atau menu.',
            self::SERVICE => 'Untuk laundry, bengkel, salon, reparasi, dan usaha layanan.',
        };
    }

    /**
     * Whether this group uses the retail inventory module.
     */
    public function usesInventory(): bool
    {
        return $this === self::RETAIL;
    }

    public function isRetail(): bool
    {
        return $this === self::RETAIL;
    }

    public function isService(): bool
    {
        return $this === self::SERVICE;
    }

    /**
     * Canonical primary values, in display order.
     *
     * @return array<int, string>
     */
    public static function canonicalValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    /**
     * Values accepted from clients during the transition: the two canonical
     * groups plus their legacy aliases. Everything else is rejected.
     *
     * @return array<int, string>
     */
    public static function acceptedInputValues(): array
    {
        return array_values(array_unique([
            ...self::canonicalValues(),
            ...array_keys(self::LEGACY_ALIASES),
        ]));
    }

    public static function isAcceptedInput(mixed $value): bool
    {
        return is_string($value)
            && in_array(strtolower(trim($value)), self::acceptedInputValues(), true);
    }

    /**
     * Map any known value (canonical or legacy) to its canonical case.
     *
     * @throws InvalidArgumentException when the value is not a known value.
     */
    public static function canonicalize(string $value): self
    {
        $key = strtolower(trim($value));
        $canonical = self::LEGACY_ALIASES[$key] ?? $key;

        return self::tryFrom($canonical)
            ?? throw new InvalidArgumentException("Unknown business type [{$value}].");
    }
}
