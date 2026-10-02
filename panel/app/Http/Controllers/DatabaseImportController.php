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
    /** 1 GB in KB — the panel nginx/php upload limits are sized to match. */
    private const MAX_DUMP_KB = 1048576;

    public function __invoke(Request $request, Application $application, DatabaseImportService $service): RedirectResponse
    {
        $validated = $request->validate([
            // Plain-text SQL only — binary gzip would break MySQL/pssql parsing
            // anyway. Large dumps are streamed and served back to the agent via
            // a signed URL, so the size cap is the only transport constraint.
            'dump' => ['required', 'file', 'max:'.self::MAX_DUMP_KB, 'extensions:sql,txt'],
        ]);

        $job = $service->import($application, $validated['dump'], $request->user()->id);

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
