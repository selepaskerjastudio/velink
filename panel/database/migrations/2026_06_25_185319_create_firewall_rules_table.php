<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Firewall rules tracked in the panel — the DB is the single source of
     * truth. FirewallService::syncRules() rebuilds UFW from this set on every
     * change (same pattern as SSH keys → authorized_keys).
     */
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            $table->string('protocol', 3)->default('tcp');    // tcp | udp
            $table->unsignedSmallInteger('port');              // 1-65535
            $table->string('action', 6)->default('allow');    // allow | deny
            $table->string('source')->nullable();             // IP/CIDR or null = anywhere
            $table->boolean('is_protected')->default(false);  // protected rules can't be deleted (SSH)

            $table->timestamps();

            $table->unique(['server_id', 'protocol', 'port', 'action', 'source'], 'firewall_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};
