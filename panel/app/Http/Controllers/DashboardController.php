<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $servers = Server::with('latestMetric')->get()->map(fn (Server $server) => [
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
            'total' => Server::count(),
            'online' => Server::where('status', 'online')->count(),
            'offline' => Server::where('status', 'offline')->count(),
            'provisioning' => Server::where('status', 'provisioning')->count(),
        ];

        $recentActivity = AuditLog::with('user:id,name')
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
