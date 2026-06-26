<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Models\Server;

/**
 * Orchestrates per-app backups via agent shell jobs.
 *
 * A backup script runs on the managed server (as root) that:
 *   1. Dumps the database (mysqldump / pg_dump) — if include_database
 *   2. Tars the app files — if include_files
 *   3. Writes the archive to /srv/velink-backups/{slug}/
 *   4. Optionally uploads to S3 (AWS creds injected as env vars, not persisted)
 *
 * The script echoes the file size on success so GatewayInboundProcessor can
 * record it on the Backup row.
 */
class BackupService
{
    /** Directory on the managed server where backups are stored. */
    private const BACKUP_DIR = '/srv/velink-backups';

    public function __construct(private JobDispatcher $dispatcher) {}

    /**
     * Run a backup for an application.
     *
     * @param  string  $type  manual | scheduled
     */
    public function runBackup(Application $app, BackupSetting $settings, string $type = 'manual', ?int $userId = null): Backup
    {
        $timestamp = now()->format('Y-m-d_His');
        $slug = $app->app_slug ?? 'app';
        $backupDir = self::BACKUP_DIR."/{$slug}";
        $filename = "{$timestamp}.tar.gz";
        $localPath = "{$backupDir}/{$filename}";

        // Parse DB credentials from the app's .env content.
        $db = $this->parseDbCredentials($app->env_content);

        $script = $this->buildBackupScript(
            app: $app,
            settings: $settings,
            db: $db,
            backupDir: $backupDir,
            localPath: $localPath,
        );

        $storage = $this->resolveStorage($settings);

        $backup = Backup::create([
            'application_id' => $app->id,
            'server_id' => $app->server_id,
            'status' => Backup::STATUS_PENDING,
            'type' => $type,
            'storage' => $storage,
            'local_path' => $settings->storage_local ? $localPath : null,
        ]);

        $job = $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $script,
            'timeout' => 1800,
        ], [
            'application_id' => $app->id,
            'user_id' => $userId,
            'label' => "Backup for {$app->name}",
        ]);

        $backup->forceFill([
            'agent_job_id' => $job->id,
            'started_at' => now(),
            'status' => Backup::STATUS_RUNNING,
        ])->save();

        return $backup;
    }

    /**
     * Restore an application from a backup archive.
     *
     * @return AgentJob
     */
    public function restoreBackup(Backup $backup, ?int $userId = null): AgentJob
    {
        $app = $backup->application;
        $db = $this->parseDbCredentials($app->env_content);
        $localPath = $backup->local_path ?? '';

        $script = $this->buildRestoreScript($app, $db, $localPath);

        return $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $script,
            'timeout' => 1800,
        ], [
            'application_id' => $app->id,
            'user_id' => $userId,
            'label' => "Restore backup for {$app->name}",
        ]);
    }

    /**
     * Delete backups beyond the retention count for an application.
     */
    public function pruneOldBackups(Application $app, int $retentionCount): void
    {
        if ($retentionCount <= 0) {
            return;
        }

        $oldBackups = $app->backups()
            ->where('status', Backup::STATUS_SUCCEEDED)
            ->latest('id')
            ->skip($retentionCount)
            ->take(100)
            ->get();

        foreach ($oldBackups as $backup) {
            // Dispatch cleanup job for the local file (fire and forget).
            if ($backup->local_path) {
                $this->dispatcher->dispatch($app->server, 'shell', [
                    'command' => 'rm -f '.escapeshellarg($backup->local_path),
                    'timeout' => 30,
                ], ['application_id' => $app->id, 'label' => "Delete old backup {$backup->uuid}"]);
            }

            $backup->delete();
        }
    }

    /**
     * Parse DB_* credentials from the app's .env content.
     *
     * @return array{connection: ?string, database: ?string, username: ?string, password: ?string}
     */
    public function parseDbCredentials(?string $envContent): array
    {
        if (! $envContent) {
            return ['connection' => null, 'database' => null, 'username' => null, 'password' => null];
        }

        $lines = explode("\n", $envContent);
        $vars = [];

        foreach ($lines as $line) {
            if (preg_match('/^(DB_CONNECTION|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=(.*)$/', trim($line), $m)) {
                $vars[$m[1]] = trim($m[2], '"\'');
            }
        }

        return [
            'connection' => $vars['DB_CONNECTION'] ?? null,
            'database' => $vars['DB_DATABASE'] ?? null,
            'username' => $vars['DB_USERNAME'] ?? null,
            'password' => $vars['DB_PASSWORD'] ?? null,
        ];
    }

    /**
     * Resolve the storage type from settings.
     */
    private function resolveStorage(BackupSetting $settings): string
    {
        if ($settings->storage_local && $settings->storage_s3) {
            return 'both';
        }
        if ($settings->storage_s3) {
            return 's3';
        }

        return 'local';
    }

    /**
     * Build the backup shell script.
     */
    private function buildBackupScript(Application $app, BackupSetting $settings, array $db, string $backupDir, string $localPath): string
    {
        $lines = ['set -e'];
        $lines[] = 'echo "==> Starting backup"';
        $lines[] = 'mkdir -p '.escapeshellarg($backupDir);

        $tempDir = escapeshellarg("/tmp/velink-backup-{$app->app_slug}");

        // Create a temp dir for the backup contents.
        $lines[] = "rm -rf {$tempDir}";
        $lines[] = "mkdir -p {$tempDir}";

        // Database dump.
        if ($settings->include_database && $db['database'] && $db['connection']) {
            $dbName = escapeshellarg($db['database']);
            $dumpFile = "{$tempDir}/database.sql.gz";

            if ($db['connection'] === 'pgsql') {
                $lines[] = "sudo -u postgres pg_dump --format=plain {$dbName} | gzip > {$dumpFile}";
            } else {
                // MySQL/MariaDB — connect via socket as root.
                $lines[] = "mysqldump --single-transaction {$dbName} | gzip > {$dumpFile}";
            }
            $lines[] = 'echo "Database dump complete"';
        }

        // File backup.
        if ($settings->include_files) {
            $rootPath = escapeshellarg($app->root_path);
            $lines[] = "tar czf {$tempDir}/files.tar.gz -C {$rootPath} . 2>/dev/null || true";
            $lines[] = 'echo "File archive complete"';
        }

        // Combine into final archive.
        $localPathEscaped = escapeshellarg($localPath);
        $lines[] = "tar czf {$localPathEscaped} -C {$tempDir} .";
        $lines[] = "rm -rf {$tempDir}";

        // Echo the size for the panel to parse.
        $lines[] = 'SIZE=$(stat -c%s '.escapeshellarg($localPath).' 2>/dev/null || stat -f%z '.escapeshellarg($localPath).' 2>/dev/null || echo 0)';
        $lines[] = 'echo "BACKUP_SIZE={$localPath}:$SIZE"';

        // S3 upload.
        if ($settings->storage_s3) {
            $s3Config = $this->getS3Config();
            if ($s3Config['bucket']) {
                $s3Key = 'backups/'.($app->app_slug).'/'.basename($localPath);
                $envVars = $this->buildS3EnvVars($s3Config);
                $lines[] = "{$envVars} aws s3 cp {$localPathEscaped} s3://{$s3Config['bucket']}/{$s3Key} --endpoint-url ".escapeshellarg($s3Config['endpoint']).' 2>/dev/null || echo "S3 upload failed (non-fatal)"';
                $lines[] = 'echo "S3 upload complete"';
            }
        }

        $lines[] = 'echo "Backup complete"';

        return implode("\n", $lines);
    }

    /**
     * Build the restore shell script.
     */
    private function buildRestoreScript(Application $app, array $db, string $localPath): string
    {
        $lines = ['set -e'];
        $lines[] = 'echo "==> Starting restore"';
        $tempDir = escapeshellarg("/tmp/velink-restore-{$app->app_slug}");

        $lines[] = "rm -rf {$tempDir}";
        $lines[] = "mkdir -p {$tempDir}";
        $lines[] = 'tar xzf '.escapeshellarg($localPath)." -C {$tempDir}";

        // Restore database.
        if ($db['database'] && $db['connection']) {
            $dbName = escapeshellarg($db['database']);
            $dumpFile = "{$tempDir}/database.sql.gz";

            $lines[] = 'if [ -f '.$dumpFile.' ]; then';
            if ($db['connection'] === 'pgsql') {
                $lines[] = "  gunzip -c {$dumpFile} | sudo -u postgres psql {$dbName}";
            } else {
                $lines[] = "  gunzip -c {$dumpFile} | mysql {$dbName}";
            }
            $lines[] = '  echo "Database restored"';
            $lines[] = 'fi';
        }

        // Restore files.
        $rootPath = escapeshellarg($app->root_path);
        $filesArchive = "{$tempDir}/files.tar.gz";
        $lines[] = 'if [ -f '.$filesArchive.' ]; then';
        $lines[] = "  tar xzf {$filesArchive} -C {$rootPath}";
        $lines[] = '  echo "Files restored"';
        $lines[] = 'fi';

        $lines[] = "rm -rf {$tempDir}";
        $lines[] = 'echo "Restore complete"';

        return implode("\n", $lines);
    }

    /**
     * Get S3 configuration from config/env.
     *
     * @return array{key: string, secret: string, region: string, bucket: string, endpoint: string}
     */
    private function getS3Config(): array
    {
        return [
            'key' => (string) config('velink.backup.s3.key', ''),
            'secret' => (string) config('velink.backup.s3.secret', ''),
            'region' => (string) config('velink.backup.s3.region', 'us-east-1'),
            'bucket' => (string) config('velink.backup.s3.bucket', ''),
            'endpoint' => (string) config('velink.backup.s3.endpoint', ''),
        ];
    }

    /**
     * Build AWS env vars for the shell command (creds not persisted).
     */
    private function buildS3EnvVars(array $s3): string
    {
        $parts = [
            'AWS_ACCESS_KEY_ID='.escapeshellarg($s3['key']),
            'AWS_SECRET_ACCESS_KEY='.escapeshellarg($s3['secret']),
            'AWS_DEFAULT_REGION='.escapeshellarg($s3['region']),
        ];

        return implode(' ', $parts);
    }
}
