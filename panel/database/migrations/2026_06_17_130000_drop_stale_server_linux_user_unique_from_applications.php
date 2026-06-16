<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the obsolete (server_id, linux_user) unique index.
     *
     * Per-app identity used to be keyed by a dedicated linux_user, so that
     * pair was unique. Since 2026_06_17_120000 every app on a server shares
     * one linux_user (config velink.webapp_user) and uniqueness moved to
     * (server_id, app_slug). The old index now caps each server at a single
     * application — any second create hits a 23505 unique violation.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'linux_user']);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['server_id', 'linux_user']);
        });
    }
};
