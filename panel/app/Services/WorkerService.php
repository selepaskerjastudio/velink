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

        $jobs = [];
        $programName = WorkerTemplates::programName($app, $worker);

        $jobs[] = $this->renderConfig($app, $worker, "Write supervisor config: {$worker->name}", $userId);

        $jobs[] = $this->shell($app, 'Reload supervisord', <<<SH
            sudo supervisorctl reread && sudo supervisorctl update
            SH, $userId);

        $jobs[] = $this->shell($app, "Start worker: {$worker->name}", <<<SH
            sudo supervisorctl start {$this->program($programName)}:*
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

        $jobs = [];
        $programName = WorkerTemplates::programName($app, $worker);

        $jobs[] = $this->renderConfig($app, $worker, "Write supervisor config: {$worker->name}", $userId);

        $jobs[] = $this->shell($app, 'Reload supervisord', <<<SH
            sudo supervisorctl reread && sudo supervisorctl update
            SH, $userId);

        $jobs[] = $this->shell($app, "Restart worker: {$worker->name}", <<<SH
            sudo supervisorctl restart {$this->program($programName)}:*
            SH, $userId);

        $worker->forceFill(['status' => 'running'])->save();

        return ['service' => $worker, 'jobs' => $jobs];
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
            sudo supervisorctl reread && sudo supervisorctl update
            SH, $userId, useSetE: false);

        $worker->delete();

        return $job;
    }

    private function renderConfig(Application $app, Service $worker, string $label, ?int $userId): AgentJob
    {
        $programName = WorkerTemplates::programName($app, $worker);

        return $this->dispatcher->dispatch($app->server, 'render_config', [
            'path' => WorkerTemplates::configPath($programName),
            'template' => WorkerTemplates::SUPERVISOR_PROGRAM,
            'vars' => WorkerTemplates::vars($app, $worker),
            'mode' => '0644',
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => $label]);
    }

    private function shell(Application $app, string $label, string $command, ?int $userId, bool $useSetE = true): AgentJob
    {
        $lines = array_map('trim', explode("\n", trim($command)));
        $header = $useSetE ? "set -e\necho \"==> {$label}\"\n" : "echo \"==> {$label}\"\n";

        return $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $header.implode("\n", $lines),
            'timeout' => 60,
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => $label]);
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
