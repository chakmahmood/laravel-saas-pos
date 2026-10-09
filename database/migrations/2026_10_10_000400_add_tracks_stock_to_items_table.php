<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            /*
             * Whether this catalog item participates in the inventory ledger.
             *
             * Defaults to false so every existing item (and every create
             * request that does not opt in explicitly) keeps the exact
             * behaviour it had before inventory existed. Nothing is turned into
             * a stock-tracked item automatically, because that would create
             * fake balances for items whose stock was never tracked.
             *
             * Stock is only processed when the store business type supports
             * inventory AND this flag is true.
             */
            $table->boolean('tracks_stock')
                ->default(false)
                ->after('is_active');

            /*
             * Inventory always filters by store and the stock flag, e.g.
             * "all stock-tracked items of this store". Matches the existing
             * `(store_id, ...)` index convention.
             */
            $table->index(['store_id', 'tracks_stock']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'tracks_stock']);
            $table->dropColumn('tracks_stock');
        });
    }
};
