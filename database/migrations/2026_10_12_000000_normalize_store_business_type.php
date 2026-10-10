<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill of `stores.business_type` from the pre-refactor values to the two
 * canonical groups.
 *
 * Mapping (see App\Enums\BusinessType):
 * - retail, restaurant            → retail
 * - laundry, repair, salon, other → service
 *
 * This is an EXPAND/BACKFILL migration: it only rewrites the legacy values and
 * never drops data. Rows already holding a canonical value are untouched, so it
 * is safe to run more than once.
 *
 * It is intentionally DATA-only (no schema change, no column default change)
 * and is NOT executed by this checkpoint. See the checkpoint report for the
 * deployment order (deploy compatibility code first, then run this backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stores')
            ->whereIn('business_type', ['restaurant'])
            ->update(['business_type' => 'retail']);

        DB::table('stores')
            ->whereIn('business_type', ['laundry', 'repair', 'salon', 'other'])
            ->update(['business_type' => 'service']);
    }

    public function down(): void
    {
        /*
         * No-op. Canonicalization cannot be reversed safely because the
         * original fine-grained value (which workflow template was intended)
         * would have to be guessed. Restore from a database backup instead.
         */
    }
};
