<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();

            /*
             * Denormalized tenant owner, so every balance query can be scoped
             * by store without joining stock_locations. A balance belongs to
             * exactly one store, location and item.
             */
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->foreignId('stock_location_id')
                ->constrained('stock_locations')
                ->restrictOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            /*
             * Operationally projected stock, always updated together with the
             * ledger inside one database transaction.
             *
             *   available = quantity_on_hand - quantity_reserved
             *
             * `quantity_reserved` holds stock promised to orders that are not
             * final yet; it never exceeds `quantity_on_hand`.
             *
             * A database CHECK constraint was deliberately NOT added: Laravel's
             * schema builder has no portable CHECK support, SQLite cannot add a
             * CHECK to an existing table, and a driver-specific raw DDL would
             * make the MySQL production schema differ from the SQLite test
             * schema. Non-negativity and reservation bounds are therefore
             * enforced by the service layer (next checkpoint). Decimal math is
             * used everywhere; floating point is never used for stock.
             */
            $table->decimal('quantity_on_hand', 12, 3)->default(0);
            $table->decimal('quantity_reserved', 12, 3)->default(0);

            $table->timestamps();

            /*
             * One balance row per location and item. Stock for the same item in
             * a different location is a different row.
             */
            $table->unique(['stock_location_id', 'item_id']);

            $table->index(['store_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
