<?php

namespace App\Casts;

use App\Enums\BusinessType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts `stores.business_type` to the canonical {@see BusinessType} enum while
 * tolerating legacy values that still live in the database.
 *
 * - Reading: legacy values (`restaurant`, `laundry`, `repair`, `salon`,
 *   `other`) are mapped to their canonical group, so pre-refactor rows never
 *   crash the application or leak a non-canonical value to the API.
 * - Writing: only the canonical value is persisted.
 *
 * @implements CastsAttributes<BusinessType, BusinessType|string|null>
 */
class BusinessTypeCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BusinessType
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BusinessType::canonicalize((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BusinessType) {
            return $value->value;
        }

        return BusinessType::canonicalize((string) $value)->value;
    }
}
