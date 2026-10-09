<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant owner. A category can never exist without a store.
             * Deleting a store removes its own categories, which is intended:
             * categories are owned by the store, not shared across tenants.
             */
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('name', 100);

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * A category name must be unique inside a single store, never
             * globally. Two different stores may reuse the same name.
             */
            $table->unique(['store_id', 'name']);

            /*
             * Supports the list endpoint, which is always scoped to a store
             * and may filter by active status.
             */
            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
