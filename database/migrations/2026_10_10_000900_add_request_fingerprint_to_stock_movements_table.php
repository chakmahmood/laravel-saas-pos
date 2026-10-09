<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive column used to distinguish an identical idempotent retry from a
     * conflicting reuse of the same idempotency key with a different payload.
     *
     * NULL for internal order-flow movements (which use deterministic keys and
     * never need payload comparison) and for any pre-existing row.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('request_fingerprint', 64)
                ->nullable()
                ->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
