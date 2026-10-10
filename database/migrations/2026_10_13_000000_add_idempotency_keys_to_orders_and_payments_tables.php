<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive idempotency support for POS checkout.
 *
 * An optional, client-supplied `idempotency_key` is stored per tenant together
 * with a `request_fingerprint` (sha256 of the canonical payload). This mirrors
 * the pattern already used by `stock_movements`:
 *
 *  - unique `(store_id, idempotency_key)` guarantees the same key can never
 *    produce two rows for a store, even under concurrency;
 *  - `request_fingerprint` distinguishes a safe retry (same key + same payload)
 *    from a conflicting reuse (same key + different payload).
 *
 * Columns are NULLABLE: existing rows and requests that do not send a key keep
 * today's behaviour. On MySQL and SQLite, multiple NULLs do not collide in a
 * unique index, so legacy rows are unaffected.
 *
 * This migration is additive and is NOT applied by this change; deploy it with
 * `php artisan migrate` (see the checkpoint report for the safe rollout steps).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 100)
                ->nullable()
                ->after('order_number');

            $table->string('request_fingerprint', 64)
                ->nullable()
                ->after('idempotency_key');

            $table->unique(
                ['store_id', 'idempotency_key'],
                'orders_store_idempotency_key_unique',
            );
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)
                ->nullable()
                ->after('reference_number');

            $table->string('request_fingerprint', 64)
                ->nullable()
                ->after('idempotency_key');

            $table->unique(
                ['store_id', 'idempotency_key'],
                'payments_store_idempotency_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_store_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_store_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
