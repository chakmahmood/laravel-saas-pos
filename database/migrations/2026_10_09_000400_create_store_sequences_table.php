<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Persistent, per-store counters used to build race-safe document
         * numbers (starting with order numbers). The counter is advanced while
         * the store row is locked, so numbers are monotonic and never derived
         * from a row count.
         */
        Schema::create('store_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('sequence_key', 100);
            $table->unsignedBigInteger('last_value')->default(0);

            $table->timestamps();

            $table->unique(['store_id', 'sequence_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_sequences');
    }
};
