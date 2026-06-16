<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sequential provisioning: jobs in the same batch run one at a time, in
     * `batch_sequence` order. The panel dispatches the next step only after the
     * previous one succeeds, so concurrent agent execution can't race ahead
     * (e.g. composer before php, or php install before the PPA is added).
     */
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('uuid')->index();
            $table->unsignedInteger('batch_sequence')->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'batch_sequence']);
        });
    }
};
