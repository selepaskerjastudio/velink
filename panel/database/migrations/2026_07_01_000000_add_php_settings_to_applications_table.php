<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-application PHP-FPM tuning (pm.* values) and PHP ini limits
     * (memory_limit, upload_max_filesize, etc.). `null` means "use the
     * code-level defaults" — see App\Provisioning\PhpSettings. Existing
     * apps are unaffected because the defaults match the values that were
     * previously hardcoded in AppTemplates::PHP_FPM_POOL.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('php_settings')->nullable()->after('stack_mode');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('php_settings');
        });
    }
};
