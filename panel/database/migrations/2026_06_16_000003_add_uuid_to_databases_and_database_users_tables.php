<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('databases')->get(['id'])->each(function ($row) {
            DB::table('databases')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('database_users')->get(['id'])->each(function ($row) {
            DB::table('database_users')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
