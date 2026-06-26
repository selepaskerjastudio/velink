<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function __construct(private BackupService $backupService) {}

    /**
     * List backups and settings for an application.
     */
    public function index(Request $request, Application $application): Response
    {
        $settings = $application->backupSetting ?: BackupSetting::create(['application_id' => $application->id]);

        $backups = $application->backups()
            ->latest('id')
            ->limit(50)
            ->get(['id', 'uuid', 'status', 'type', 'size_bytes', 'storage', 'local_path', 'started_at', 'completed_at', 'created_at'])
            ->map(fn (Backup $b) => [
                'id' => $b->uuid,
                'status' => $b->status,
                'type' => $b->type,
                'size_bytes' => $b->size_bytes,
                'storage' => $b->storage,
                'started_at' => $b->started_at?->toIso8601String(),
                'completed_at' => $b->completed_at?->toIso8601String(),
                'created_at' => $b->created_at?->toIso8601String(),
            ]);

        return Inertia::render('apps/backups', [
            'application' => [
                'id' => $application->uuid,
                'name' => $application->name,
            ],
            'server' => [
                'id' => $application->server->uuid,
                'name' => $application->server->name,
            ],
            'backups' => $backups,
            'settings' => [
                'schedule' => $settings->schedule,
                'retention_count' => $settings->retention_count,
                'include_database' => $settings->include_database,
                'include_files' => $settings->include_files,
                'storage_local' => $settings->storage_local,
                'storage_s3' => $settings->storage_s3,
            ],
        ]);
    }

    /**
     * Trigger a manual backup.
     */
    public function store(Request $request, Application $application): RedirectResponse
    {
        $settings = $application->backupSetting ?: BackupSetting::create(['application_id' => $application->id]);

        $this->backupService->runBackup($application, $settings, 'manual', $request->user()->id);

        AuditLogger::log(
            action: 'backup.manual',
            description: "Manual backup triggered for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('backups.index', $application);
    }

    /**
     * Update backup settings.
     */
    public function updateSettings(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'schedule' => ['required', Rule::in(BackupSetting::SCHEDULES)],
            'retention_count' => ['required', 'integer', 'min:1', 'max:100'],
            'include_database' => ['boolean'],
            'include_files' => ['boolean'],
            'storage_local' => ['boolean'],
            'storage_s3' => ['boolean'],
        ]);

        $settings = $application->backupSetting ?: BackupSetting::create(['application_id' => $application->id]);
        $settings->update($validated);

        AuditLogger::log(
            action: 'backup.settings_updated',
            description: "Backup settings updated for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('backups.index', $application);
    }

    /**
     * Restore from a backup.
     */
    public function restore(Request $request, Application $application, Backup $backup): RedirectResponse
    {
        abort_if($backup->application_id !== $application->id, 404);
        abort_if($backup->status !== Backup::STATUS_SUCCEEDED, 422, 'Only succeeded backups can be restored.');

        $this->backupService->restoreBackup($backup, $request->user()->id);

        AuditLogger::log(
            action: 'backup.restored',
            description: "Restore started from backup for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('backups.index', $application);
    }

    /**
     * Delete a backup.
     */
    public function destroy(Request $request, Application $application, Backup $backup): RedirectResponse
    {
        abort_if($backup->application_id !== $application->id, 404);

        $backup->delete();

        AuditLogger::log(
            action: 'backup.deleted',
            description: "Backup deleted for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('backups.index', $application);
    }
}
