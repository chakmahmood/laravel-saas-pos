<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            /*
             * Cashier who owns the shift. Kept even if the user is removed.
             */
            $table->foreignId('cashier_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 20)->default('open');

            $table->unsignedBigInteger('opening_cash')->default(0);

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            /*
             * Reconciliation values, filled when the shift is closed.
             * expected_cash and actual_cash are non-negative; difference may be
             * negative (cash short), so it is a signed bigint.
             */
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('difference')->nullable();

            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            /*
             * Race guard. Non-null only while the shift is open and set to
             * "<store_id>:<cashier_id>"; the unique index then allows at most
             * one OPEN shift per cashier per store, while unlimited closed
             * history is allowed because closed rows are NULL (NULLs are
             * distinct in a unique index on both MySQL and SQLite).
             *
             * It is maintained by CashSessionService, not by clients. A MySQL
             * STORED generated column was rejected here because InnoDB refuses
             * to add the store foreign key to a table with a stored generated
             * column, so a transactional guard column is used instead.
             */
            $table->string('open_guard', 80)->nullable();

            $table->timestamps();

            $table->unique('open_guard');

            $table->index(['store_id', 'opened_at']);
            $table->index(['store_id', 'cashier_id']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
