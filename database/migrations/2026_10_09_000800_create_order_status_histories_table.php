<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Bounded, focused audit trail for status changes (financial and
         * fulfillment). This is deliberately not a generic audit framework.
         */
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->string('from_fulfillment_status', 20)->nullable();
            $table->string('to_fulfillment_status', 20)->nullable();
            $table->string('from_payment_status', 20)->nullable();
            $table->string('to_payment_status', 20)->nullable();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason', 255)->nullable();

            // Immutable rows: only created_at is tracked.
            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
