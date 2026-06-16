<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Set when a successful "Enable SSL" job lands for the app's domain.
            $table->timestamp('ssl_enabled_at')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('ssl_enabled_at');
        });
    }
};
