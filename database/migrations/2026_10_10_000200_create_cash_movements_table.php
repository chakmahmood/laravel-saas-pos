<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->foreignId('cash_session_id')
                ->constrained('cash_sessions')
                ->cascadeOnDelete();

            /*
             * Who recorded the movement. Kept even if the user is removed.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('type', 20);
            $table->unsignedBigInteger('amount');
            $table->string('reason', 255);

            $table->timestamps();

            $table->index(['cash_session_id', 'type']);
            $table->index(['store_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
