<?php

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
             * Canonical values are `retail` and `service` (see
             * App\Enums\BusinessType). The default is `service`, matching the
             * historical `other` default (which maps to the `service` group),
             * so a row inserted without an explicit type never becomes an
             * inventory-capable store by accident.
             *
             * NOTE: This migration was already applied before the canonical
             * refactor; the default is edited here only because it previously
             * referenced a removed enum case (`BusinessType::OTHER`). The
             * existing development database keeps its original column default;
             * a targeted data-backfill migration normalizes legacy rows.
             */
            $table->string('business_type', 30)
                ->default('service')
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
