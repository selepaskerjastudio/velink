<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('role', 20)->default('member')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Every pre-existing user is a pre-existing operator: grandfather them all
        // to admin so the new default of 'member' only applies to rows created
        // after this migration. Must run in the same up() as the column add —
        // splitting this into a follow-up step would lock the current single
        // user out of their own panel.
        DB::table('users')->update(['role' => 'admin']);

        DB::table('users')->whereNull('uuid')->orderBy('id')->cursor()->each(
            fn ($user) => DB::table('users')->where('id', $user->id)->update(['uuid' => (string) Str::uuid()])
        );

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'role', 'is_active']);
        });
    }
};
