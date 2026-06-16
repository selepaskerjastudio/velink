<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // custom | laravel | wordpress | static
            $table->string('app_type')->default('custom')->after('php_version');
            // production | development
            $table->string('stack_mode')->default('production')->after('app_type');
            // Filesystem/pool-safe identifier; drives the webapp folder, the
            // php-fpm pool name + socket, the pool conf filename, and the nginx
            // log filenames. Unique per server.
            $table->string('app_slug')->nullable()->after('linux_user');
        });

        // Backfill existing rows so already-provisioned apps keep working:
        // their per-app identity used to be keyed by linux_user.
        DB::table('applications')->whereNull('app_slug')->update([
            'app_slug' => DB::raw('linux_user'),
        ]);

        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['server_id', 'app_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'app_slug']);
            $table->dropColumn(['app_type', 'stack_mode', 'app_slug']);
        });
    }
};
