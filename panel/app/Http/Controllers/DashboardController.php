<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Server;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Rebuilt per use: scopeVisibleTo() is a constraint on a fresh query,
        // so the counts below cannot share one builder instance.
        $visible = fn () => Server::query()->visibleTo($user);
        $visibleIds = Server::query()->visibleTo($user)->select('servers.id');

        $servers = $visible()->with('latestMetric')->get()->map(fn (Server $server) => [
            'id' => $server->uuid,
            'name' => $server->name,
            'public_ip' => $server->public_ip,
            'status' => $server->status,
            'cpu_percent' => $server->latestMetric?->cpu_percent,
            'mem_total' => $server->latestMetric?->mem_total,
            'mem_used' => $server->latestMetric?->mem_used,
            'disk_total' => $server->latestMetric?->disk_total,
            'disk_used' => $server->latestMetric?->disk_used,
            'load1' => $server->latestMetric?->load1,
        ]);

        $serverCounts = [
            'total' => $visible()->count(),
            'online' => $visible()->where('status', 'online')->count(),
            'offline' => $visible()->where('status', 'offline')->count(),
            'provisioning' => $visible()->where('status', 'provisioning')->count(),
        ];

        // audit_logs.server_id is nullable — account-level actions (SSH keys,
        // git credentials, Cloudflare tokens) carry no server. A member should
        // see their own account actions alongside their servers' activity.
        $recentActivity = AuditLog::with('user:id,name')
            ->when(! $user->isAdmin(), fn ($query) => $query->where(
                fn ($q) => $q->whereIn('server_id', $visibleIds)
                    ->orWhere(fn ($q2) => $q2->whereNull('server_id')->where('user_id', $user->id))
            ))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at->toIso8601String(),
                'user' => $log->user ? ['name' => $log->user->name] : null,
            ]);

        $recentDeployments = Deployment::with(['application:id,name', 'user:id,name'])
            ->when(! $user->isAdmin(), fn ($query) => $query->whereIn(
                'application_id',
                Application::query()->whereIn('server_id', $visibleIds)->select('id')
            ))
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Deployment $dep) => [
                'id' => $dep->uuid,
                'application' => $dep->application ? ['name' => $dep->application->name] : null,
                'branch' => $dep->branch,
                'status' => $dep->status,
                'triggered_by' => $dep->triggered_by,
                'started_at' => $dep->started_at?->toIso8601String(),
                'finished_at' => $dep->finished_at?->toIso8601String(),
                'user' => $dep->user ? ['name' => $dep->user->name] : null,
            ]);

        return Inertia::render('dashboard', [
            'servers' => $servers,
            'serverCounts' => $serverCounts,
            'recentActivity' => $recentActivity,
            'recentDeployments' => $recentDeployments,
        ]);
    }
}
