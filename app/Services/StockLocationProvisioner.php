<?php

namespace App\Services;

use App\Enums\StockLocationType;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Idempotent provisioning of the one mandatory default stock location per
 * store.
 *
 * Why a service (not a migration or seeder):
 * - Migrations in this project are DDL only and must never touch tenant data.
 * - Seeders here are for global reference data (plans), not per-tenant backfill.
 * - Provisioning is reused for new stores at registration in a later checkpoint
 *   and is called in bulk for existing stores through the
 *   `stock:provision-locations` command.
 *
 * The default location is identified by `is_default = true` and protected by
 * the portable unique `default_guard` column, so running this repeatedly can
 * never create a second default for the same store.
 */
class StockLocationProvisioner
{
    public const DEFAULT_LOCATION_NAME = 'Lokasi Utama';

    /**
     * Ensure the store has a default location and return it.
     *
     * Safe to call repeatedly and safe against concurrent callers: if two
     * processes race, the unique `default_guard` index rejects the loser, which
     * then returns the winner's row instead of failing.
     */
    public function ensureDefaultForStore(Store $store): StockLocation
    {
        $existing = $this->defaultLocationFor($store);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return StockLocation::query()->create([
                'store_id' => $store->getKey(),
                'name' => self::DEFAULT_LOCATION_NAME,
                'code' => null,
                'type' => StockLocationType::OUTLET,
                'is_default' => true,
                'is_active' => true,
                'default_guard' => (string) $store->getKey(),
            ]);
        } catch (QueryException $exception) {
            /*
             * Either a concurrent caller created the default first, or a
             * location with the conventional name already exists (unique per
             * store). Reuse it and promote it to default if needed.
             */
            $existing = $this->defaultLocationFor($store)
                ?? $this->locationByName($store);

            if ($existing === null) {
                throw $exception;
            }

            if (! $existing->is_default) {
                $existing->forceFill([
                    'is_default' => true,
                    'is_active' => true,
                    'default_guard' => (string) $store->getKey(),
                ])->save();
            }

            return $existing->refresh();
        }
    }

    /**
     * Provision a default location for every store that does not have one yet.
     *
     * @return int number of stores provisioned (not already having a default)
     */
    public function provisionMissingLocations(): int
    {
        $provisioned = 0;

        Store::query()
            ->whereDoesntHave('stockLocations', function (Builder $query): void {
                $query->where('is_default', true);
            })
            ->orderBy('id')
            ->chunkById(200, function ($stores) use (&$provisioned): void {
                foreach ($stores as $store) {
                    $this->ensureDefaultForStore($store);
                    $provisioned++;
                }
            });

        return $provisioned;
    }

    private function defaultLocationFor(Store $store): ?StockLocation
    {
        return StockLocation::query()
            ->where('store_id', $store->getKey())
            ->where('is_default', true)
            ->first();
    }

    private function locationByName(Store $store): ?StockLocation
    {
        return StockLocation::query()
            ->where('store_id', $store->getKey())
            ->where('name', self::DEFAULT_LOCATION_NAME)
            ->first();
    }
}
