<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Services\AnsiStripper;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentLogController extends Controller
{
    /**
     * Render a dedicated full-page view of a single deployment's log output.
     *
     * Authorization mirrors the rest of the app: any authenticated panel user
     * can view deployments. Route-model binding resolves Deployment by uuid.
     */
    public function show(Request $request, Deployment $deployment): Response
    {
        $deployment->load('application.server');

        // Previous and next deployments for the same application, by id.
        $previous = Deployment::where('application_id', $deployment->application_id)
            ->where('id', '<', $deployment->id)
            ->latest('id')
            ->first();
        $next = Deployment::where('application_id', $deployment->application_id)
            ->where('id', '>', $deployment->id)
            ->oldest('id')
            ->first();

        $log = (string) ($deployment->log ?? '');

        return Inertia::render('deployments/show', [
            'deployment' => [
                'id' => $deployment->uuid,
                'status' => $deployment->status,
                'branch' => $deployment->branch,
                'mode' => $deployment->mode,
                'triggered_by' => $deployment->triggered_by,
                'commit_hash' => $deployment->commit_hash,
                'commit_message' => $deployment->commit_message,
                'log' => AnsiStripper::toHtml($log),
                'log_html' => AnsiStripper::toHtml($log, colorize: true),
                'started_at' => $deployment->started_at?->toIso8601String(),
                'finished_at' => $deployment->finished_at?->toIso8601String(),
                'application_name' => $deployment->application->name,
                'application_uuid' => $deployment->application->uuid,
                'server_name' => $deployment->application->server->name,
                'server_uuid' => $deployment->application->server->uuid,
            ],
            'previousId' => $previous?->uuid,
            'nextId' => $next?->uuid,
        ]);
    }
}
