<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(): Response
    {
        $logs = AuditLog::with(['user:id,name', 'server:id,uuid,name'])
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
}
