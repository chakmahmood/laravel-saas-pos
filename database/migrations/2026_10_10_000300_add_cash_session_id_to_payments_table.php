<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            /*
             * Cash payments are attributed to the cashier's open shift so the
             * drawer reconciliation can count them. Non-cash payments keep this
             * null. Legacy cash payments (before this phase) stay null and are
             * never silently attributed to a new shift.
             */
            $table->foreignId('cash_session_id')
                ->nullable()
                ->after('order_id')
                ->constrained('cash_sessions')
                ->nullOnDelete();

            $table->index(['cash_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropIndex(['cash_session_id', 'status']);
            $table->dropColumn('cash_session_id');
        });
    }
};
