<?php

namespace App\Http\Controllers;

use App\Models\ServerAlert;
use Inertia\Inertia;
use Inertia\Response;

class ServerAlertController extends Controller
{
    public function index(): Response
    {
        $activeAlerts = ServerAlert::with('server:id,uuid,name')
            ->active()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ServerAlert $alert) => [
                'id' => $alert->id,
                'server_id' => $alert->server->uuid,
                'server_name' => $alert->server->name,
                'metric_type' => $alert->metric_type,
                'value' => $alert->value,
                'threshold' => $alert->threshold,
                'message' => $alert->message,
                'created_at' => $alert->created_at->toIso8601String(),
            ]);

        $recentResolved = ServerAlert::with('server:id,uuid,name')
            ->resolved()
            ->latest('resolved_at')
            ->limit(20)
            ->get()
            ->map(fn (ServerAlert $alert) => [
                'id' => $alert->id,
                'server_id' => $alert->server->uuid,
                'server_name' => $alert->server->name,
                'metric_type' => $alert->metric_type,
                'message' => $alert->message,
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ]);

        return Inertia::render('alerts/index', [
            'activeAlerts' => $activeAlerts,
            'recentResolved' => $recentResolved,
            'activeCount' => ServerAlert::active()->count(),
        ]);
    }
}
