<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\AuditLogger;
use App\Services\DatabaseImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Import a .sql dump into an application's database. Available to every user
 * who can reach the application (admins + members assigned to its server via
 * EnforceServerScope) — the import runs as the app's own database user, so
 * member uploads stay scoped to that app's database.
 */
class DatabaseImportController extends Controller
{
    public function __invoke(Request $request, Application $application, DatabaseImportService $service): RedirectResponse
    {
        $validated = $request->validate([
            // Plain-text SQL only: the content transits the gateway as a JSON
            // string, which binary gzip would not survive.
            'dump' => ['required', 'file', 'max:20480', 'extensions:sql,txt'],
        ]);

        $job = $service->import(
            $application,
            (string) file_get_contents($validated['dump']->path()),
            $request->user()->id,
        );

        AuditLogger::log(
            action: 'database.imported',
            description: "Database import started for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
            properties: ['app_uuid' => $application->uuid, 'job_uuid' => $job->uuid],
        );

        return redirect()->route('backups.index', $application)
            ->with('success', 'Database import queued — watch the jobs list for progress.');
    }
}
