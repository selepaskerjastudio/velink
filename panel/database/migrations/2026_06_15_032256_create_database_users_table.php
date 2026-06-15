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
        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('engine'); // mysql | mariadb | postgres | mongodb
            $table->string('username');
            $table->text('password');
            $table->string('host')->default('%');
            $table->json('grants')->nullable(); // {"db_name": ["ALL"], ...}
            $table->timestamps();

            $table->unique(['server_id', 'username', 'host']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_users');
    }
};
