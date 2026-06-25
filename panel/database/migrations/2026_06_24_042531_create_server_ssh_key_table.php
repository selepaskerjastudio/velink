<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pivot tracking which SSH keys have been deployed to which server. The
     * authoritative source of truth for the contents of authorized_keys on a
     * server is the set of rows here for that server — SshKeyService rewrites
     * the whole authorized_keys file from this set on every deploy/revoke.
     */
    public function up(): void
    {
        Schema::create('server_ssh_key', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ssh_key_id')->constrained()->cascadeOnDelete();
            $table->timestamp('deployed_at')->useCurrent();
            $table->timestamps();

            // A key can only be deployed once to a given server.
            $table->unique(['server_id', 'ssh_key_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_ssh_key');
    }
};
