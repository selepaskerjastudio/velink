<?php

use App\Models\Server;
use App\Models\SystemUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the target system_user to the server_ssh_key pivot so a key can be
     * deployed to any SSH-accessible user on a server (not just a hardcoded
     * admin). For backward compatibility, existing deployments are backfilled:
     * each server that already had SSH keys gets a `velink-admin` system user
     * and its pivot rows are repointed at it, so nothing changes for users
     * who deployed keys before this feature.
     */
    public function up(): void
    {
        Schema::table('server_ssh_key', function (Blueprint $table) {
            $table->foreignId('system_user_id')
                ->nullable()
                ->after('ssh_key_id')
                ->constrained('system_users')
                ->cascadeOnDelete();

            // Replace the (server_id, ssh_key_id) unique index with one keyed
            // on the target user, so the same key may target several users.
            $table->dropUnique(['server_id', 'ssh_key_id']);
            $table->unique(['server_id', 'ssh_key_id', 'system_user_id'], 'server_ssh_key_target_unique');
        });

        // Backfill: materialise the implicit `velink-admin` target for every
        // server that already has deployments, then point its pivot rows at it.
        $serverIds = \DB::table('server_ssh_key')->pluck('server_id')->unique();
        foreach ($serverIds as $serverId) {
            $admin = SystemUser::firstOrCreate(
                ['server_id' => $serverId, 'username' => 'velink-admin'],
                ['shell' => '/bin/bash', 'is_sudo' => true, 'is_system_reserved' => true],
            );
            \DB::table('server_ssh_key')
                ->where('server_id', $serverId)
                ->whereNull('system_user_id')
                ->update(['system_user_id' => $admin->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_ssh_key', function (Blueprint $table) {
            $table->dropUnique('server_ssh_key_target_unique');
            $table->dropForeign(['system_user_id']);
            $table->dropColumn('system_user_id');
            $table->unique(['server_id', 'ssh_key_id']);
        });
    }
};
