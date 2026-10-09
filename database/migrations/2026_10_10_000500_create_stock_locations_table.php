<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();

            /*
             * Tenant owner. A location can never exist without a store.
             * Deleting a store removes its own locations.
             */
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('code', 30)->nullable();

            /*
             * StockLocationType: warehouse | outlet | other.
             * Stored as a short string validated by the PHP enum.
             */
            $table->string('type', 20)->default('outlet');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            /*
             * At most one DEFAULT location per store, enforced portably.
             *
             * A plain unique index on the boolean `is_default` cannot express
             * this: MySQL and SQLite both allow many rows with the value false.
             * A partial unique index (`WHERE is_default = 1`) works on SQLite
             * but not on MySQL. A nullable guard column does work on both:
             *
             *   default_guard = "<store_id>" while is_default = true, else NULL.
             *
             * The unique index accepts only one non-null guard per store
             * (store ids never repeat), while NULLs are treated as distinct on
             * both MySQL and SQLite, so unlimited non-default locations are
             * allowed. This mirrors the proven `cash_sessions.open_guard`
             * pattern already used in this project.
             *
             * The column is maintained by the provisioning/service layer, never
             * derived from client input.
             */
            $table->string('default_guard', 80)->nullable();

            $table->timestamps();

            /*
             * Names and codes are unique per store, never globally. NULL codes
             * are allowed and do not collide.
             */
            $table->unique(['store_id', 'name']);
            $table->unique(['store_id', 'code']);
            $table->unique('default_guard');

            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
