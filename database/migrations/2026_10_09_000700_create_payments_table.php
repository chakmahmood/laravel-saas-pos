<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->string('payment_method', 30);

            // Integer rupiah. Always positive; validated server-side.
            $table->unsignedBigInteger('amount');

            $table->string('status', 20)->default('completed');

            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('paid_at')->nullable();

            /*
             * Who recorded the payment.
             */
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Void (audit) instead of delete. A voided payment never counts
             * toward the order paid amount.
             */
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
