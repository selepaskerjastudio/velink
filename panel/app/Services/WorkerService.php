<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Service;
use App\Provisioning\WorkerTemplates;

/**
 * Manages per-application Supervisord "programs" (typically Laravel queue
 * workers) backed by `services` rows with type='supervisor'.
 */
class WorkerService
{
    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * @return array{service: Service, jobs: array<int, AgentJob>}
     */
    public function create(Application $app, string $name, string $command, int $numprocs, ?int $userId): array
    {
        $worker = Service::create([
            'server_id' => $app->server_id,
            'application_id' => $app->id,
            'type' => 'supervisor',
            'name' => $name,
            'command' => $command,
            'status' => 'unknown',
            'config' => ['numprocs' => $numprocs],
        ]);

        $jobs = $this->dispatchLifecycle($app, $worker, "Start worker: {$worker->name}", <<<SH
            sudo supervisorctl start {$this->program(WorkerTemplates::programName($app, $worker))}:*
            SH, $userId);

        $worker->forceFill(['status' => 'running'])->save();

        return ['service' => $worker, 'jobs' => $jobs];
    }

    /**
     * @return array{service: Service, jobs: array<int, AgentJob>}
     */
    public function update(Service $worker, string $command, int $numprocs, ?int $userId): array
    {
        $app = $worker->application;

        $worker->forceFill([
            'command' => $command,
            'config' => ['numprocs' => $numprocs],
        ])->save();

        $jobs = $this->dispatchLifecycle($app, $worker, "Restart worker: {$worker->name}", <<<SH
            sudo supervisorctl restart {$this->program(WorkerTemplates::programName($app, $worker))}:*
            SH, $userId);

        $worker->forceFill(['status' => 'running'])->save();

        return ['service' => $worker, 'jobs' => $jobs];
    }

    /**
     * Write the supervisor program config, reload supervisord, then run the
     * final control command — as a phased batch so each step is guaranteed to
     * finish (not just be dispatched) before the next one runs. Plain
     * dispatch() calls race: the agent runs every job in its own goroutine, so
     * a "start" job sent right after "reload" can reach the agent and execute
     * before reread/update has registered the new program group, failing with
     * "no such group".
     *
     * @return array<int, AgentJob>
     */
    private function dispatchLifecycle(Application $app, Service $worker, string $finalLabel, string $finalCommand, ?int $userId): array
    {
        $programName = WorkerTemplates::programName($app, $worker);

        return $this->dispatcher->queueBatch($app->server, [
            [
                'name' => "Write supervisor config: {$worker->name}",
                'type' => 'render_config',
                'phase' => 0,
                'application_id' => $app->id,
                'params' => [
                    'path' => WorkerTemplates::configPath($programName),
                    'template' => WorkerTemplates::SUPERVISOR_PROGRAM,
                    'vars' => WorkerTemplates::vars($app, $worker),
                    'mode' => '0644',
                ],
            ],
            [
                'name' => 'Reload supervisord',
                'type' => 'shell',
                'phase' => 1,
                'application_id' => $app->id,
                'params' => $this->shellParams($this->reloadSupervisordCommand(), 'Reload supervisord', useSetE: false),
            ],
            [
                'name' => $finalLabel,
                'type' => 'shell',
                'phase' => 2,
                'application_id' => $app->id,
                'params' => $this->shellParams($finalCommand, $finalLabel),
            ],
        ], $userId);
    }

    public function control(Service $worker, string $action, ?int $userId): AgentJob
    {
        $app = $worker->application;
        $programName = WorkerTemplates::programName($app, $worker);

        $job = $this->shell($app, ucfirst($action)." worker: {$worker->name}", <<<SH
            sudo supervisorctl {$action} {$this->program($programName)}:*
            SH, $userId);

        $worker->forceFill([
            'status' => $action === 'stop' ? 'stopped' : 'running',
        ])->save();

        return $job;
    }

    public function delete(Service $worker, ?int $userId): AgentJob
    {
        $app = $worker->application;
        $programName = WorkerTemplates::programName($app, $worker);
        $configPath = WorkerTemplates::configPath($programName);

        $job = $this->shell($app, "Remove worker: {$worker->name}", <<<SH
            sudo supervisorctl stop {$this->program($programName)}:* || true
            sudo rm -f {$this->path($configPath)}
            {$this->reloadSupervisordCommand()}
            SH, $userId, useSetE: false);

        $worker->delete();

        return $job;
    }

    /**
     * `supervisorctl reread && supervisorctl update`, retried a few times.
     * `update` can transiently fault with STILL_RUNNING when an unrelated
     * program (e.g. a horizon worker draining its long stopwaitsecs) is
     * mid-stop when it runs — retrying absorbs that instead of failing the
     * whole reload/delete.
     */
    private function reloadSupervisordCommand(): string
    {
        return <<<'SH'
            sudo supervisorctl reread
            ok=0
            for attempt in 1 2 3 4 5; do
                if sudo supervisorctl update; then ok=1; break; fi
                sleep 3
            done
            [ "$ok" = 1 ]
            SH;
    }

    private function shell(Application $app, string $label, string $command, ?int $userId, bool $useSetE = true): AgentJob
    {
        return $this->dispatcher->dispatch(
            $app->server,
            'shell',
            $this->shellParams($command, $label, $useSetE),
            ['application_id' => $app->id, 'user_id' => $userId, 'label' => $label],
        );
    }

    /**
     * @return array{command: string, timeout: int}
     */
    private function shellParams(string $command, string $label, bool $useSetE = true): array
    {
        $lines = array_map('trim', explode("\n", trim($command)));
        $header = $useSetE ? "set -e\necho \"==> {$label}\"\n" : "echo \"==> {$label}\"\n";

        return [
            'command' => $header.implode("\n", $lines),
            'timeout' => 60,
        ];
    }

    /**
     * Quote a supervisord program name for safe interpolation into shell
     * heredocs (program names are sanitized to [a-z0-9_] already, but quote
     * defensively).
     */
    private function program(string $programName): string
    {
        return escapeshellarg($programName);
    }

    /**
     * Quote a filesystem path for safe interpolation into shell heredocs.
     */
    private function path(string $path): string
    {
        return escapeshellarg($path);
    }
}
