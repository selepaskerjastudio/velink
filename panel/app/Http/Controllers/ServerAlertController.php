<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerAlert;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerAlertController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $visibleIds = Server::query()->visibleTo($user)->select('servers.id');

        $scoped = fn ($query) => $query->when(
            ! $user->isAdmin(),
            fn ($q) => $q->whereIn('server_id', $visibleIds)
        );

        $activeAlerts = ServerAlert::with('server:id,uuid,name')
            ->tap($scoped)
            ->active()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ServerAlert $alert) => [
                'id' => $alert->id,
                'server_id' => $alert->server?->uuid,
                'server_name' => $alert->server?->name,
                'metric_type' => $alert->metric_type,
                'value' => $alert->value,
                'threshold' => $alert->threshold,
                'message' => $alert->message,
                'created_at' => $alert->created_at->toIso8601String(),
            ]);

        $recentResolved = ServerAlert::with('server:id,uuid,name')
            ->tap($scoped)
            ->resolved()
            ->latest('resolved_at')
            ->limit(20)
            ->get()
            ->map(fn (ServerAlert $alert) => [
                'id' => $alert->id,
                'server_id' => $alert->server?->uuid,
                'server_name' => $alert->server?->name,
                'metric_type' => $alert->metric_type,
                'message' => $alert->message,
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ]);

        return Inertia::render('alerts/index', [
            'activeAlerts' => $activeAlerts,
            'recentResolved' => $recentResolved,
            'activeCount' => ServerAlert::query()->tap($scoped)->active()->count(),
        ]);
    }
}
