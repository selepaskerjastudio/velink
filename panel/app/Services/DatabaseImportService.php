<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Application;
use Illuminate\Validation\ValidationException;

/**
 * Imports a member-uploaded .sql dump into an application's database.
 *
 * The dump is written to a root-only temp file on the server and piped into
 * the database **as the application's dedicated DB user** (credentials from
 * the app's .env) — never as root. The user's grants cover only that app's
 * database, so a hostile dump cannot touch other apps' databases, users, or
 * grants the way a root `mysql < dump` would.
 */
class DatabaseImportService
{
    public function __construct(
        private JobDispatcher $dispatcher,
        private BackupService $backups,
    ) {
    }

    public function import(Application $app, string $dumpContent, int $userId): AgentJob
    {
        $creds = $this->backups->parseDbCredentials($app->env_content);

        foreach (['connection', 'database', 'username', 'password'] as $key) {
            if (empty($creds[$key])) {
                throw ValidationException::withMessages([
                    'dump' => 'This application has no database credentials in its .env — create it with a database (or configure DB_* in the .env tab) first.',
                ]);
            }
        }

        $path = '/tmp/velink-import-'.$app->app_slug.'-'.uniqid().'.sql';

        // 1. Upload the dump to a private temp file on the server.
        $this->dispatcher->dispatch($app->server, 'write_file', [
            'path' => $path,
            'content' => $dumpContent,
            'mode' => '0600',
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => 'Upload database dump']);

        // 2. Import as the app's DB user, then remove the temp file. Jobs run
        //    sequentially per server, so ordering with the upload is safe.
        return $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $this->buildScript($creds, $path, $app->name),
            'timeout' => 1800,
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => 'Import database']);
    }

    /**
     * @param  array{connection: string, database: string, username: string, password: string}  $creds
     */
    private function buildScript(array $creds, string $path, string $appName): string
    {
        $user = escapeshellarg($creds['username']);
        $password = escapeshellarg($creds['password']);
        $database = escapeshellarg($creds['database']);
        $file = escapeshellarg($path);

        $import = match ($creds['connection']) {
            'mysql' => "mysql -u {$user} -p{$password} -h localhost {$database} < {$file}",
            'pgsql' => "PGPASSWORD={$password} psql -U {$user} -h 127.0.0.1 -d {$database} -f {$file} -v ON_ERROR_STOP=1",
            default => throw ValidationException::withMessages([
                'dump' => 'Database imports support MySQL/MariaDB and PostgreSQL apps only.',
            ]),
        };

        return <<<SH
            set -e
            echo "==> Import database for {$appName}"
            {$import}
            rm -f {$file}
            echo "==> Import finished"
            SH;
    }
}
