<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->float('cpu_percent');
            $table->unsignedBigInteger('mem_total');
            $table->unsignedBigInteger('mem_used');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_used');
            $table->float('load1');
            $table->timestamp('recorded_at');
            // NO updated_at — this is append-only timeseries data.

            $table->index(['server_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
