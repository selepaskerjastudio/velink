<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Server;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $visibleIds = Server::query()->visibleTo($user)->select('servers.id');

        // server_id is nullable: account-level actions (ssh_key.created,
        // git_credential.created, cloudflare.token_added) have no server.
        // Filtering on visible servers alone would hide a member's own
        // account activity from them, so those are matched separately.
        $logs = AuditLog::with(['user:id,name', 'server:id,uuid,name'])
            ->when(! $user->isAdmin(), fn ($query) => $query->where(
                fn ($q) => $q->whereIn('server_id', $visibleIds)
                    ->orWhere(fn ($q2) => $q2->whereNull('server_id')->where('user_id', $user->id))
            ))
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'user' => $log->user ? ['name' => $log->user->name] : null,
                'server' => $log->server ? ['id' => $log->server->uuid, 'name' => $log->server->name] : null,
            ]);

        return Inertia::render('audit-logs/index', [
            'logs' => $logs,
        ]);
    }

    public function serverIndex(Server $server): Response
    {
        $logs = AuditLog::with(['user:id,name'])
            ->where('server_id', $server->id)
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'user' => $log->user ? ['name' => $log->user->name] : null,
            ]);

        return Inertia::render('servers/activity', [
            'server' => [
                'id' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->public_ip,
                'status' => $server->status,
            ],
            'logs' => $logs,
        ]);
    }
}
