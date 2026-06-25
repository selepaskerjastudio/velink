<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Cached on-disk size of the app's root directory, in bytes, refreshed
            // on demand via a `du -sb` agent job (see ApplicationController). Null
            // until the first refresh succeeds.
            $table->unsignedBigInteger('directory_size_bytes')->nullable()->after('root_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('directory_size_bytes');
        });
    }
};
