<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending');   // pending|running|succeeded|failed
            $table->string('type', 10)->default('manual');      // manual|scheduled
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage', 5)->default('local');     // local|s3|both
            $table->string('local_path')->nullable();
            $table->string('s3_key')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('agent_job_id')->nullable()->constrained('agent_jobs')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
