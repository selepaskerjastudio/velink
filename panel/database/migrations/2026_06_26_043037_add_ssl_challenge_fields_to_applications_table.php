<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('ssl_challenge', 4)->nullable()->after('ssl_enabled_at'); // http | dns
            $table->string('ssl_dns_provider')->nullable()->after('ssl_challenge');    // cloudflare
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['ssl_challenge', 'ssl_dns_provider']);
        });
    }
};
