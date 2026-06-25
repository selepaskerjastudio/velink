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
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            // UUID is the public route key (see HasUuidRouteKey) and is the value
            // exposed to the frontend; the numeric id never leaves the panel.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Human label, e.g. "MacBook Pro" — helps tell keys apart.
            $table->string('name');

            // Full OpenSSH public key line: "ssh-ed25519 AAAA... [comment]".
            $table->text('public_key');

            // "SHA256:..." computed from the base64 blob (see SshKeyService).
            $table->string('fingerprint');

            // Key type parsed from the prefix: ssh-ed25519 / ssh-rsa / ecdsa-...
            $table->string('type');

            // Optional trailing comment (often user@host).
            $table->string('comment')->nullable();

            $table->timestamps();

            // Same key (fingerprint) can't be added twice by the same user;
            // a different user may register the same fingerprint.
            $table->unique(['user_id', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
