<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // When true, the app's nginx vhost trusts the X-Forwarded-* headers
            // sent by an upstream TLS-terminating proxy (the Velink Caddy edge,
            // Cloudflare, or the operator's own reverse proxy) and injects
            // fastcgi_param HTTPS + set_real_ip_from so PHP sees the original
            // scheme + client IP. Without this, apps behind a proxy emit http://
            // asset URLs (Mixed Content) and lose the real client address.
            $table->boolean('behind_reverse_proxy')->default(false)->after('ssl_dns_provider');
        });

        // Backfill existing apps from their server's edge-proxy flag, so apps
        // already deployed behind the Caddy edge (e.g. report-qurban.sholeh.app)
        // get the fix on the next vhost render instead of needing a manual toggle.
        DB::table('applications as a')
            ->join('servers as s', 's.id', '=', 'a.server_id')
            ->where('s.uses_edge_proxy', true)
            ->update(['a.behind_reverse_proxy' => true]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('behind_reverse_proxy');
        });
    }
};
