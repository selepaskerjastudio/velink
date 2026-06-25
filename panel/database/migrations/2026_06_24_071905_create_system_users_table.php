<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tracks OS login accounts managed per server. These are SSH-accessible
     * users (separate from the shared `velink` webapp user which has a nologin
     * shell). SSH keys are deployed to a system user's authorized_keys, so the
     * user is the deployment target — see the server_ssh_key pivot.
     */
    public function up(): void
    {
        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            // UUID is the public route key (HasUuidRouteKey) and the value
            // exposed to the frontend; the numeric id never leaves the panel.
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            // OS username, validated upstream against ^[a-z_][a-z0-9_-]{0,31}$.
            $table->string('username', 32);

            // Login shell: /bin/bash, /bin/sh, or /usr/sbin/nologin.
            $table->string('shell')->default('/bin/bash');

            // Whether the user is in the sudo group.
            $table->boolean('is_sudo')->default(false);

            // System-reserved users (root, the webapp user) cannot be deleted
            // via the panel — they exist for discovery/display only.
            $table->boolean('is_system_reserved')->default(false);

            $table->timestamps();

            // A username must be unique per server.
            $table->unique(['server_id', 'username']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
