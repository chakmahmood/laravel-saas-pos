<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag accounts whose initial password was set by an owner/admin so the POS
 * forces a password change on first login.
 *
 * Additive and non-destructive: existing and self-registered accounts keep the
 * default (false), so they are never forced to rotate their password.
 *
 * NOT RUN as part of Backend Checkpoint 7; see the checkpoint report for the
 * pending-migration status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')
                ->default(false)
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
