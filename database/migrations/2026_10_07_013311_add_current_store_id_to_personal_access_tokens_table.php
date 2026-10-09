<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('current_store_id')
                ->nullable()
                ->after('abilities')
                ->constrained('stores')
                ->nullOnDelete();

            $table->index('current_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['current_store_id']);
            $table->dropIndex(['current_store_id']);
            $table->dropColumn('current_store_id');
        });
    }
};
