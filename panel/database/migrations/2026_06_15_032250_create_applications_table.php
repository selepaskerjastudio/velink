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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('git_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('root_path');
            $table->string('linux_user');
            $table->string('php_version'); // 7.4 | 8.1 | 8.2 | 8.3 | 8.4
            $table->string('repository')->nullable(); // e.g. owner/repo
            $table->string('branch')->default('main');
            $table->string('deploy_mode')->default('inplace'); // inplace | zero_downtime
            $table->text('deploy_script')->nullable();
            $table->text('env_content')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
