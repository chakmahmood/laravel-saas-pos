<?php

namespace App\Services;

use App\Exceptions\ItemLimitReachedException;
use App\Models\Item;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for the universal catalog.
 *
 * All item creation goes through this service so that:
 *
 * 1. The catalog quota (Plan::max_products) is checked under the SAME row lock
 *    on the store for every caller, serializing concurrent creates.
 * 2. The quota count is performed AFTER the lock is acquired and inside the
 *    transaction that inserts the item.
 * 3. Database unique violations (SKU / barcode) that slip past validation
 *    because of a concurrent request are translated into a clean 422 instead
 *    of a 500.
 *
 * Concurrency note: row locking is enforced by MySQL 8 InnoDB. SQLite (used by
 * the test suite) does not implement row locks, so SQLite tests only verify the
 * protocol and behaviour, not true concurrent isolation.
 */
class CatalogItemService
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ItemLimitReachedException
     * @throws ValidationException
     */
    public function create(Store $store, array $attributes): Item
    {
        try {
            return DB::transaction(function () use ($store, $attributes) {
                /*
                 * Lock the store row first. Every item creation uses this same
                 * lock, so two concurrent creates for the same store are
                 * serialized and the second one counts the first insert.
                 */
                $lockedStore = Store::query()
                    ->whereKey($store->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertWithinQuota($lockedStore);

                return $lockedStore->items()->create($attributes);
            });
        } catch (QueryException $exception) {
            $this->translateUniqueViolation($exception);

            throw $exception;
        }
    }

    /**
     * Assert that the store can still add one more catalog item.
     *
     * The quota counts every item of every type, active and inactive alike.
     * A null limit (or no active subscription) means unlimited.
     *
     * @throws ItemLimitReachedException
     */
    public function assertWithinQuota(Store $store): void
    {
        $limit = $store->activeSubscription?->plan?->max_products;

        if ($limit === null) {
            return;
        }

        if ($store->items()->count() >= $limit) {
            throw new ItemLimitReachedException($limit);
        }
    }

    /**
     * Translate a raw database unique violation into a validation error so the
     * API stays consistent even when two requests race past validation.
     */
    private function translateUniqueViolation(QueryException $exception): void
    {
        $message = $exception->getMessage();

        $isDuplicate = str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');

        if (! $isDuplicate) {
            return;
        }

        $field = match (true) {
            str_contains($message, 'barcode') => 'barcode',
            str_contains($message, 'sku') => 'sku',
            default => null,
        };

        if ($field === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Nilai '.$field.' sudah digunakan pada toko ini.',
        ]);
    }
}
