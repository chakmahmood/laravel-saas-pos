<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();

            // User yang memiliki toko ini.
            $table->foreignId('owner_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();

            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();

            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->string('logo')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * Optimized for queries such as:
             *
             * WHERE owner_id = ?
             * AND is_active = true
             */
            $table->index(['owner_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
