<?php

namespace App\Provisioning;

use App\Models\Application;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * Supervisord program config template for per-application queue workers
 * (`services` rows with type='supervisor'), rendered by the agent via the
 * `render_config` action (Go text/template, vars are a flat map so keys
 * here must match the map keys exactly, snake_case).
 */
class WorkerTemplates
{
    public const SUPERVISOR_PROGRAM = <<<'CONF'
        [program:{{.program_name}}]
        command={{.command}}
        directory={{.directory}}
        user={{.linux_user}}
        numprocs={{.numprocs}}
        autostart=true
        autorestart=true
        stopasgroup=true
        killasgroup=true
        redirect_stderr=true
        stdout_logfile={{.log_path}}
        stopwaitsecs=3600
        CONF;

    /**
     * @return array<string, string>
     */
    public static function vars(Application $app, Service $worker): array
    {
        $programName = self::programName($app, $worker);

        return [
            'program_name' => $programName,
            'command' => (string) $worker->command,
            'directory' => $app->root_path,
            'linux_user' => $app->linux_user,
            'numprocs' => (string) ($worker->config['numprocs'] ?? 1),
            'log_path' => self::logPath($programName),
        ];
    }

    /**
     * Derive the supervisord program name for a worker, e.g.
     * "myapp_default". Sanitized to lowercase [a-z0-9_] for safe use in
     * both the program name and the config file path.
     */
    public static function programName(Application $app, Service $worker): string
    {
        return self::sanitize("{$app->linux_user}_{$worker->name}");
    }

    public static function logPath(string $programName): string
    {
        return "/var/log/supervisor/{$programName}.log";
    }

    public static function configPath(string $programName): string
    {
        return "/etc/supervisor/conf.d/{$programName}.conf";
    }

    private static function sanitize(string $value): string
    {
        $slug = Str::slug($value, '_');

        return preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';
    }
}
