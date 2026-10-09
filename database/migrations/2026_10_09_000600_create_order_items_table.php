<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            /*
             * Catalog reference is optional and nulled if the item is later
             * removed. The snapshot columns below keep the transaction intact.
             */
            $table->foreignId('item_id')
                ->nullable()
                ->constrained('items')
                ->nullOnDelete();

            /*
             * Point-in-time snapshots. Later catalog changes must never alter
             * transaction history.
             */
            $table->string('item_name', 150);
            $table->string('item_sku', 64)->nullable();
            $table->string('item_type', 20);
            $table->string('unit', 20)->default('pcs');

            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);

            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
