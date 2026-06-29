<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // When true, this server has no public edge of its own — it sits
            // behind a shared Caddy. The panel pushes reverse-proxy routes to
            // Caddy and TLS is terminated there (target stays HTTP-only).
            $table->boolean('uses_edge_proxy')->default(false)->after('private_ip');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('uses_edge_proxy');
        });
    }
};
