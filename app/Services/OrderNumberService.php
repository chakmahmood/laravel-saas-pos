<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreSequence;
use Carbon\CarbonInterface;

/**
 * Builds race-safe, per-store document numbers.
 *
 * The counter lives in `store_sequences` and is advanced with an atomic
 * increment. Callers MUST already hold a `lockForUpdate()` on the store row
 * (see OrderService), so two concurrent calls for the same store are
 * serialized and cannot produce the same number.
 *
 * The number is never derived from a row count.
 */
class OrderNumberService
{
    public function next(Store $store, ?CarbonInterface $date = null): string
    {
        $date ??= now();

        $key = 'orders:'.$date->format('Y-m-d');

        $sequence = StoreSequence::query()->firstOrCreate(
            [
                'store_id' => $store->getKey(),
                'sequence_key' => $key,
            ],
            [
                'last_value' => 0,
            ],
        );

        $sequence->increment('last_value');

        /*
         * Read the persisted value explicitly instead of relying on the
         * in-memory increment, so the emitted number always matches the
         * committed counter (and starts at 0001, not 0000).
         */
        $sequence->refresh();

        return sprintf('TRX-%s-%04d', $date->format('Ymd'), $sequence->last_value);
    }
}
