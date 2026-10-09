<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            /*
             * Customers are archived (soft deleted) instead of removed, so
             * transaction history stays readable. `deleted_at` is part of the
             * model, not a global scope flag.
             */
            $table->softDeletes();

            /*
             * No unique constraint on phone or email: family members may share
             * a phone, a walk-in customer may be created twice, and MySQL would
             * treat multiple NULLs inconsistently with an accidental composite
             * unique. Duplicate detection is an application concern, documented
             * in docs/customer-api.md.
             */
            $table->index(['store_id', 'name']);
            $table->index(['store_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
