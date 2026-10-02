<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Imports a member-uploaded .sql dump (up to 1 GB) into an application's
 * database.
 *
 * Large dumps never transit the gateway as JSON payloads — that path caps out
 * at a few MB. Instead the upload is streamed to panel storage, and the agent
 * downloads it from a single-use, expiring signed URL over HTTPS (the same
 * host it already dials for the gateway).
 *
 * The import itself runs **as the application's dedicated DB user**
 * (credentials from the app's .env) — never as root. The user's grants cover
 * only that app's database, so a hostile dump cannot touch other apps'
 * databases, users, or grants the way a root `mysql < dump` would.
 */
class DatabaseImportService
{
    public function __construct(
        private JobDispatcher $dispatcher,
        private BackupService $backups,
    ) {
    }

    public function import(Application $app, UploadedFile $dump, int $userId): AgentJob
    {
        $creds = $this->backups->parseDbCredentials($app->env_content);

        foreach (['connection', 'database', 'username', 'password'] as $key) {
            if (empty($creds[$key])) {
                throw ValidationException::withMessages([
                    'dump' => 'This application has no database credentials in its .env — create it with a database (or configure DB_* in the .env tab) first.',
                ]);
            }
        }

        // Stream the dump onto panel storage (no memory blow-up at 1 GB);
        // the agent pulls it from the signed URL below. Random UUID name —
        // the URL is unguessable and expires in an hour.
        $filename = Str::uuid()->toString().'.sql';
        $dump->storeAs('dumps', $filename);
        $url = URL::temporarySignedRoute('dumps.download', now()->addHour(), ['dump' => $filename]);

        $path = '/tmp/velink-import-'.$app->app_slug.'-'.uniqid().'.sql';

        return $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $this->buildScript($creds, $path, $url, $app->name),
            'timeout' => 3600,
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => 'Import database']);
    }

    /**
     * Every interpolated value is shell-escaped and used in an unquoted
     * context — escapeshellarg's single-quoted form is only safe outside
     * quotes, so the echo line deliberately carries no double quotes (a
     * double-quoted echo would let a `"` in the app name break out).
     *
     * @param  array{connection: string, database: string, username: string, password: string}  $creds
     */
    private function buildScript(array $creds, string $path, string $url, string $appName): string
    {
        $user = escapeshellarg($creds['username']);
        // Env-var prefixes keep the password out of `ps` arguments, matching
        // the PGPASSWORD convention.
        $password = escapeshellarg($creds['password']);
        $database = escapeshellarg($creds['database']);
        $file = escapeshellarg($path);
        $from = escapeshellarg($url);
        $name = escapeshellarg($appName);

        $import = match ($creds['connection']) {
            'mysql' => "MYSQL_PWD={$password} mysql -u {$user} -h localhost {$database} < {$file}",
            'pgsql' => "PGPASSWORD={$password} psql -U {$user} -h 127.0.0.1 -d {$database} -f {$file} -v ON_ERROR_STOP=1",
            default => throw ValidationException::withMessages([
                'dump' => 'Database imports support MySQL/MariaDB and PostgreSQL apps only.',
            ]),
        };

        return <<<SH
            set -e
            echo ==> Import database for {$name}
            curl -fsSL --retry 3 -o {$file} {$from}
            {$import}
            rm -f {$file}
            echo ==> Import finished
            SH;
    }
}
