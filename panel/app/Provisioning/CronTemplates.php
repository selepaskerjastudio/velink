<?php

namespace App\Provisioning;

use App\Models\CronJob;
use App\Models\Server;

/**
 * The managed /etc/cron.d/velink drop-in file. Rendered by the agent via
 * the `render_config` action (Go text/template, vars are a flat map so keys
 * here must match the map keys exactly, snake_case).
 *
 * cron.d drop-ins require a trailing newline and a `user` field on every
 * line (unlike user crontabs), which the template below provides.
 */
class CronTemplates
{
    public const FILE_PATH = '/etc/cron.d/velink';

    // Each field is either a bare `*`, a `*/N` step, or a numeric range/list
    // (`5`, `1-5`, `1,2,3`, `1-5/2`). Bare `*` plus `/` without digits (e.g.
    // `*/`, `**/5`) is rejected; cron itself would refuse those at runtime.
    public const SCHEDULE_REGEX = '/^(\*(\/\d+)?|[\d,\/\-]+)(\s+(\*(\/\d+)?|[\d,\/\-]+)){4}$/';

    public const USER_REGEX = '/^[a-z_][a-z0-9_-]*$/';

    /**
     * System accounts a member may not schedule jobs as. A cron entry runs an
     * arbitrary command as its `user` field, so allowing `root` here would hand
     * any member of an assigned server a root shell on a one-minute schedule —
     * making the web-terminal and system-user restrictions meaningless.
     * Administrators are not subject to this list.
     *
     * @var list<string>
     */
    public const MEMBER_FORBIDDEN_USERS = [
        'root',
        'daemon',
        'bin',
        'sys',
        'sync',
        'sudo',
        'admin',
        'velink-admin',
        'www-data',
    ];

    public const CRON_FILE = <<<'CONF'
        # Managed by Velink — do not edit manually.
        {{range .jobs}}{{.schedule}} {{.user}} {{.command}}
        {{end}}
        CONF;

    /**
     * @return array{jobs: array<int, array{schedule: string, user: string, command: string}>}
     */
    public static function vars(Server $server): array
    {
        return [
            'jobs' => CronJob::where('server_id', $server->id)
                ->where('status', 'active')
                ->get()
                ->map(fn (CronJob $job) => [
                    'schedule' => $job->schedule,
                    'user' => $job->user,
                    'command' => $job->command,
                ])
                ->values()
                ->all(),
        ];
    }

    public static function filePath(): string
    {
        return self::FILE_PATH;
    }
}
