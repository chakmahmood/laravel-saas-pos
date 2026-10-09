<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('order_number', 40);

            /*
             * Customer is optional (walk-in). nullOnDelete is a safety net if a
             * customer is ever hard deleted; the API archives instead, so the
             * reference normally survives.
             */
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            /*
             * Staff member who created the order. Snapshot of responsibility;
             * kept even if the user is later removed.
             */
            $table->foreignId('cashier_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Money is integer rupiah, computed and verified server-side.
             */
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);

            /*
             * Two independent statuses. Payment status is derived from valid
             * payments; fulfillment status is operational.
             */
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('fulfillment_status', 20)->default('pending');

            $table->text('notes')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['store_id', 'order_number']);

            $table->index(['store_id', 'placed_at']);
            $table->index(['store_id', 'payment_status']);
            $table->index(['store_id', 'fulfillment_status']);
            $table->index(['store_id', 'customer_id']);
            $table->index(['store_id', 'cashier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
