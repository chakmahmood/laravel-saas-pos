<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Append-only inventory ledger. This is the source of truth for stock:
         * corrections are made with new (reversing) movements, never by
         * editing or deleting a row.
         *
         * Rows are never deleted by business logic. `restrictOnDelete` on the
         * location and item protects the history; `cascadeOnDelete` on the
         * store means deleting a tenant removes its own ledger, consistent
         * with the rest of the schema.
         */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

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
             * StockMovementType stored as a short string validated by the PHP
             * enum: opening, purchase_in, sale_out, reservation,
             * reservation_release, adjustment_in, adjustment_out, transfer_in,
             * transfer_out, return_in, return_out, reversal.
             *
             * The direction of a movement is derived from its type; a reversal
             * derives its direction from the movement it reverses.
             */
            $table->string('type', 30);

            /*
             * Always positive. The type determines whether the movement
             * increases on-hand, decreases on-hand, reserves or releases.
             * decimal(12,3) matches the project quantity convention; floating
             * point is never used for stock.
             */
            $table->decimal('quantity', 12, 3);

            /*
             * Optional cost snapshot at the time of the movement, in integer
             * minor units (nullable = unknown / not tracked), matching the
             * money convention used by `items.cost_price`.
             */
            $table->unsignedBigInteger('unit_cost')->nullable();

            /*
             * Optional links to the transaction that caused the movement.
             * Order history is never touched by inventory.
             */
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('order_items')
                ->nullOnDelete();

            /*
             * Self reference used by `reversal` movements to point at the
             * movement they correct.
             */
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('stock_movements')
                ->nullOnDelete();

            /*
             * Idempotency key scoped to the store, e.g.
             * "order:123:reserve" or "transfer:7:out:42". A retried request or
             * a double-triggered commit must never move stock twice. NULL keys
             * are allowed (manual movements without a natural key); NULLs are
             * distinct on both MySQL and SQLite so they never collide.
             */
            $table->string('idempotency_key', 120)->nullable();

            $table->string('note', 255)->nullable();

            /*
             * Who recorded the movement. Kept even if the user is removed.
             */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * When the movement physically happened (defaults to now).
             */
            $table->timestamp('occurred_at')->useCurrent();

            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['store_id', 'item_id', 'occurred_at']);
            $table->index(['store_id', 'stock_location_id']);
            $table->index(['store_id', 'order_id']);
            $table->index(['store_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
