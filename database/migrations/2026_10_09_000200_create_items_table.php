<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant owner. An item can never exist without a store.
             * Deleting a store removes its own items.
             */
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            /*
             * Optional category. RESTRICT prevents deleting a category that is
             * still referenced by an item. The app also checks this relation
             * and returns a clean 409 before reaching the database.
             */
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('name', 150);

            /*
             * ItemType: product | service | menu | package.
             * Stored as a short string validated by the PHP enum.
             */
            $table->string('type', 20);

            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();

            $table->text('description')->nullable();

            /*
             * Money is stored as integer minor units. For IDR this is a plain
             * rupiah amount. cost_price = null means "not tracked"; it must
             * never be silently coerced to zero.
             */
            $table->unsignedBigInteger('cost_price')->nullable();
            $table->unsignedBigInteger('selling_price')->default(0);

            $table->string('unit', 20)->default('pcs');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * SKU / barcode are unique per store, never globally. MySQL treats
             * NULL values as distinct, so many items without SKU/barcode are
             * allowed.
             */
            $table->unique(['store_id', 'sku']);
            $table->unique(['store_id', 'barcode']);

            $table->index(['store_id', 'is_active']);
            $table->index(['store_id', 'type']);
            $table->index(['store_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
