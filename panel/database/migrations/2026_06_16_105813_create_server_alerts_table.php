<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type');       // cpu, memory, disk
            $table->float('value');
            $table->float('threshold');
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'metric_type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_alerts');
    }
};
