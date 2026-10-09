<?php

use App\Enums\BusinessType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            /*
             * Primary business type of the store.
             *
             * Default `other` guarantees backward compatibility: every store
             * created before this migration (and every register request that
             * does not send a business type) keeps working without changes.
             */
            $table->string('business_type', 30)
                ->default(BusinessType::OTHER->value)
                ->after('is_active');

            $table->index('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['business_type']);
            $table->dropColumn('business_type');
        });
    }
};
