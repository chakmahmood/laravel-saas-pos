<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('role', 50);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * A user can only belong to a store once.
             */
            $table->unique(['store_id', 'user_id']);

            /*
             * Useful for queries such as:
             *
             * WHERE store_id = ?
             * AND is_active = true
             */
            $table->index(['store_id', 'is_active']);

            /*
             * Useful for finding all active stores
             * belonging to a user.
             */
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
    }
};
