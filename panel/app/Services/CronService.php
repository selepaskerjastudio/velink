<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\CronJob;
use App\Models\Server;
use App\Provisioning\CronTemplates;

/**
 * Manages CronJob rows and keeps the managed /etc/cron.d/velink drop-in
 * file on the target server in sync via the `render_config` action.
 */
class CronService
{
    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * @param  array{application_id?: int|null, user: string, command: string, schedule: string}  $attrs
     * @return array{cronJob: CronJob, job: AgentJob}
     */
    public function create(Server $server, array $attrs, ?int $userId): array
    {
        $cronJob = CronJob::create([
            'server_id' => $server->id,
            'application_id' => $attrs['application_id'] ?? null,
            'user' => $attrs['user'],
            'command' => $attrs['command'],
            'schedule' => $attrs['schedule'],
            'status' => 'active',
        ]);

        $job = $this->sync($server, $userId);

        return ['cronJob' => $cronJob, 'job' => $job];
    }

    /**
     * @param  array{application_id?: int|null, user: string, command: string, schedule: string}  $attrs
     * @return array{cronJob: CronJob, job: AgentJob}
     */
    public function update(CronJob $cronJob, array $attrs, ?int $userId): array
    {
        $cronJob->forceFill([
            'application_id' => $attrs['application_id'] ?? null,
            'user' => $attrs['user'],
            'command' => $attrs['command'],
            'schedule' => $attrs['schedule'],
        ])->save();

        $job = $this->sync($cronJob->server, $userId);

        return ['cronJob' => $cronJob, 'job' => $job];
    }

    /**
     * @return array{cronJob: CronJob, job: AgentJob}
     */
    public function toggle(CronJob $cronJob, ?int $userId): array
    {
        $cronJob->forceFill([
            'status' => $cronJob->status === 'active' ? 'paused' : 'active',
        ])->save();

        $job = $this->sync($cronJob->server, $userId);

        return ['cronJob' => $cronJob, 'job' => $job];
    }

    public function delete(CronJob $cronJob, ?int $userId): AgentJob
    {
        $server = $cronJob->server;
        $cronJob->delete();

        return $this->sync($server, $userId);
    }

    private function sync(Server $server, ?int $userId): AgentJob
    {
        return $this->dispatcher->dispatch($server, 'render_config', [
            'path' => CronTemplates::filePath(),
            'template' => CronTemplates::CRON_FILE,
            'vars' => CronTemplates::vars($server),
            'mode' => '0644',
        ], ['user_id' => $userId, 'label' => 'Update cron jobs']);
    }
}
