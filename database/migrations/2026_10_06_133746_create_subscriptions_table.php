<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->foreignId('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            $table->string('status', 30)->default('active');

            $table->string('billing_cycle', 20)->default('monthly');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index([
                'store_id',
                'status',
            ]);

            $table->index([
                'plan_id',
                'status',
            ]);

            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
