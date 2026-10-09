<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive integration columns for inventory. No existing column is changed
     * or dropped. Orders that contain no stock-tracked item keep both columns
     * NULL and behave exactly as before.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * The physical location this order fulfils from. Chosen server-side
             * from the store's active default location; never taken from client
             * input. NULL for orders without stock-tracked items.
             */
            $table->foreignId('stock_location_id')
                ->nullable()
                ->after('fulfillment_status')
                ->constrained('stock_locations')
                ->nullOnDelete();

            /*
             * Set exactly once when the order's stock is committed (fulfillment
             * becomes `completed`). Doubles as the idempotency guard so a retry
             * can never reduce stock twice. NULL while stock is only reserved
             * or when the order never touches inventory.
             */
            $table->timestamp('stock_committed_at')
                ->nullable()
                ->after('stock_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['stock_location_id']);
            $table->dropColumn(['stock_location_id', 'stock_committed_at']);
        });
    }
};
