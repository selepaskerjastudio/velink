<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('schedule', 10)->default('off');     // off|daily|weekly|monthly
            $table->unsignedSmallInteger('retention_count')->default(7);
            $table->boolean('include_database')->default(true);
            $table->boolean('include_files')->default(true);
            $table->boolean('storage_local')->default(true);
            $table->boolean('storage_s3')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
