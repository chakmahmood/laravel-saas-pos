<?php

namespace App\Services;

use App\Enums\BusinessType;
use App\Enums\StockLocationType;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent provisioning of the one mandatory default stock location per
 * store.
 *
 * Why a service (not a migration or seeder):
 * - Migrations in this project are DDL only and must never touch tenant data.
 * - Seeders here are for global reference data (plans), not per-tenant backfill.
 * - Provisioning runs inside the store registration transaction for new stores
 *   and in bulk for existing stores through the `stock:provision-locations`
 *   command.
 *
 * The default location is identified by `is_default = true` and protected by
 * the portable unique `default_guard` column, so running this repeatedly can
 * never create a second default for the same store.
 *
 * Concurrency: the whole operation runs in a transaction and every write path
 * treats a unique violation as "another caller won the race". A bare
 * `firstOrCreate` is NOT enough here: on MySQL, a concurrent insert can still
 * produce a duplicate-key error, so the failure must be caught and re-read
 * instead of surfacing as a 500. `lockForUpdate` is deliberately not used,
 * because locking a non-existent row does not prevent another connection from
 * inserting it.
 */
class StockLocationProvisioner
{
    public const DEFAULT_LOCATION_NAME = 'Lokasi Utama';

    /**
     * Ensure the store has a default location and return it.
     *
     * Safe to call repeatedly and safe against concurrent callers.
     */
    public function ensureDefaultForStore(Store $store): StockLocation
    {
        return DB::transaction(function () use ($store): StockLocation {
            /*
             * Serialize provisioning per store by locking the store row first,
             * following the project's canonical lock order (store -> children).
             * This removes the duplicate-insert race entirely; the unique
             * `default_guard` index remains as a database-level backstop.
             */
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->defaultLocationFor($lockedStore);

            if ($existing !== null) {
                return $existing;
            }

            try {
                return StockLocation::query()->create([
                    'store_id' => $lockedStore->getKey(),
                    'name' => self::DEFAULT_LOCATION_NAME,
                    'code' => null,
                    'type' => StockLocationType::OUTLET,
                    'is_default' => true,
                    'is_active' => true,
                    'default_guard' => (string) $lockedStore->getKey(),
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                /*
                 * Backstop for a caller that bypassed the store lock: a
                 * concurrent caller created the default first, or a location
                 * with the conventional name already exists (unique per store).
                 *
                 * The re-read MUST be a current (locking) read: under MySQL's
                 * default REPEATABLE READ isolation the transaction's snapshot
                 * was taken before the winner committed, so a plain SELECT would
                 * not see the new row and we would wrongly rethrow.
                 */
                $existing = $this->defaultLocationFor($lockedStore, lock: true)
                    ?? $this->locationByName($lockedStore, lock: true);

                if ($existing === null) {
                    throw $exception;
                }

                return $this->promoteToDefault($lockedStore, $existing, $exception);
            }
        }, 3);
    }

    /**
     * Provision a default location for every inventory-capable store that does
     * not have one yet.
     *
     * Stores whose business type does not use inventory are skipped: they must
     * not silently receive an inventory location they are not allowed to use.
     *
     * @return int number of stores provisioned (not already having a default)
     */
    public function provisionMissingLocations(): int
    {
        $provisioned = 0;

        Store::query()
            ->whereIn('business_type', $this->inventoryBusinessTypes())
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

    /**
     * @return array<int, string>
     */
    private function inventoryBusinessTypes(): array
    {
        return collect(BusinessType::cases())
            ->filter(fn (BusinessType $type): bool => $type->usesInventory())
            ->map(fn (BusinessType $type): string => $type->value)
            ->values()
            ->all();
    }

    private function promoteToDefault(
        Store $store,
        StockLocation $location,
        QueryException $original,
    ): StockLocation {
        if ($location->is_default) {
            return $location->refresh();
        }

        try {
            $location->forceFill([
                'is_default' => true,
                'is_active' => true,
                'default_guard' => (string) $store->getKey(),
            ])->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Another caller became the default first.
            $default = $this->defaultLocationFor($store, lock: true);

            if ($default !== null) {
                return $default;
            }

            throw $original;
        }

        return $location->refresh();
    }

    private function defaultLocationFor(Store $store, bool $lock = false): ?StockLocation
    {
        $query = StockLocation::query()
            ->where('store_id', $store->getKey())
            ->where('is_default', true);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function locationByName(Store $store, bool $lock = false): ?StockLocation
    {
        $query = StockLocation::query()
            ->where('store_id', $store->getKey())
            ->where('name', self::DEFAULT_LOCATION_NAME);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
