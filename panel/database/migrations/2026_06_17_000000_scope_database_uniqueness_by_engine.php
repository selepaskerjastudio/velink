<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database/user names are unique per ENGINE, not per server: the same name
     * (e.g. "app") can exist independently on MariaDB and PostgreSQL.
     */
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'name']);
            $table->unique(['server_id', 'engine', 'name']);
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'username', 'host']);
            $table->unique(['server_id', 'engine', 'username', 'host']);
        });
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine', 'name']);
            $table->unique(['server_id', 'name']);
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine', 'username', 'host']);
            $table->unique(['server_id', 'username', 'host']);
        });
    }
};
