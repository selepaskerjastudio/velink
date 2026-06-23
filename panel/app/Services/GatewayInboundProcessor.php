<?php

namespace App\Services;

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Support\GatewayProtocol;

/**
 * Applies messages arriving from the gateway bus (agent -> panel) to the
 * database and broadcasts the resulting state to the browser via Reverb.
 *
 * Kept free of any transport concerns so it can be unit-tested directly.
 */
class GatewayInboundProcessor
{
    /**
     * Components and PHP version provisioned automatically on first agent connect.
     * 'base' is implicitly prepended by ProvisionService::order().
     */
    private const AUTO_COMPONENTS = ['nginx', 'certbot', 'supervisor', 'redis', 'php', 'composer', 'node'];

    private const AUTO_PHP_VERSIONS = ['7.4', '8.1', '8.2', '8.3', '8.4'];

    public function __construct(
        private JobDispatcher $dispatcher,
        private ServiceManager $serviceManager,
        private ProvisionService $provisionService,
    ) {}

    /**
     * Handle one envelope from the inbound channel (job output / result / metrics).
     */
    public function handleInbound(string $payload): void
    {
        $env = json_decode($payload, true);
        if (! is_array($env)) {
            return;
        }

        // Metrics and sysinfo have no job_id — handle them before the job_id check.
        if (($env['type'] ?? '') === GatewayProtocol::TYPE_METRICS) {
            $this->handleMetrics($env);

            return;
        }

        if (($env['type'] ?? '') === GatewayProtocol::TYPE_SYSINFO) {
            $this->handleSysinfo($env);

            return;
        }

        $uuid = $env['job_id'] ?? null;
        if (! $uuid) {
            return;
        }

        $job = AgentJob::where('uuid', $uuid)->first();
        if (! $job) {
            return;
        }

        $body = is_array($env['payload'] ?? null) ? $env['payload'] : [];

        switch ($env['type'] ?? '') {
            case GatewayProtocol::TYPE_JOB_OUTPUT:
                $wasRunning = $job->status === AgentJob::STATUS_RUNNING;
                $job->markRunning();
                // First sign of life on a provisioning step → its service(s) are
                // now installing.
                if (! $wasRunning && $job->batch_id !== null) {
                    $this->serviceManager->setUnitsStatus(
                        $job->server,
                        $this->serviceManager->serviceNamesForJobLabel((string) $job->label),
                        ServiceManager::STATUS_INSTALLING,
                    );
                }
                $chunk = (string) ($body['data'] ?? '');
                if ($chunk !== '') {
                    $job->appendOutput($chunk);
                }
                break;

            case GatewayProtocol::TYPE_JOB_RESULT:
                $exit = (int) ($body['exit_code'] ?? 0);
                if ($exit === 0) {
                    $job->markSucceeded($exit);
                    if ($job->label === ServiceManager::PROBE_LABEL) {
                        $this->serviceManager->seedFromProbeOutput($job->server, $job->output ?? '');
                        // Probe found nothing installed → escalate to full provisioning.
                        if (! $job->server->services()->where('type', 'systemd')->exists()) {
                            $this->provisionService->provision(
                                $job->server,
                                self::AUTO_COMPONENTS,
                                ['php_versions' => self::AUTO_PHP_VERSIONS],
                            );
                            $this->serviceManager->seedForServer(
                                $job->server,
                                self::AUTO_COMPONENTS,
                                self::AUTO_PHP_VERSIONS,
                            );
                        }
                    }

                    if ($job->batch_id !== null) {
                        // Provisioning step done → its service(s) are running.
                        $this->serviceManager->setUnitsStatus(
                            $job->server,
                            $this->serviceManager->serviceNamesForJobLabel((string) $job->label),
                            ServiceManager::STATUS_RUNNING,
                        );
                        $this->advancePhase($job);
                    } else {
                        // A control job (e.g. "Restart nginx") finished — resolve
                        // the optimistic restarting/stopped state.
                        $this->serviceManager->applyControlJobResult($job->server, (string) $job->label, succeeded: true);
                    }
                } else {
                    $job->markFailed($exit, $body['error'] ?? null);

                    if ($job->batch_id !== null) {
                        // This step's service(s) failed to install.
                        $this->serviceManager->setUnitsStatus(
                            $job->server,
                            $this->serviceManager->serviceNamesForJobLabel((string) $job->label),
                            ServiceManager::STATUS_NOT_INSTALLED,
                        );
                        $this->advancePhase($job);
                    } else {
                        $this->serviceManager->applyControlJobResult($job->server, (string) $job->label, succeeded: false);
                    }
                }
                break;

            default:
                return;
        }

        // A per-application provisioning step settled — advance the app's own
        // lifecycle (provisioning → active/failed) once its jobs are done.
        if ($job->application_id !== null) {
            $this->syncApplicationProvisioning($job);

            // A successful "Enable SSL for {domain}" certbot job means the app
            // now serves HTTPS — record it so the UI reflects the real state.
            if ($job->status === AgentJob::STATUS_SUCCEEDED
                && $job->label !== null
                && str_starts_with($job->label, 'Enable SSL for')) {
                $job->application?->forceFill(['ssl_enabled_at' => now()])->save();
            }

            // A successful "Measure directory size" `du -sb` job reports the
            // app root size as "<bytes>\t<path>" on stdout — capture it. The
            // agent streams stdout earlier via a job_output event, so it is
            // already accumulated on $job->output by the time the result lands.
            if ($job->status === AgentJob::STATUS_SUCCEEDED
                && $job->label === 'Measure directory size') {
                $output = is_string($job->output) ? $job->output : '';
                if (preg_match('/(\d+)/', $output, $m)) {
                    $job->application?->forceFill(['directory_size_bytes' => (int) $m[1]])->save();
                }
            }
        }

        event(new AgentJobUpdated($job->refresh()));
    }

    /**
     * Transition an application out of the `provisioning` state based on its
     * agent jobs. App-provisioning steps are dispatched un-batched and run
     * concurrently on the agent, so the app is "active" only once every one of
     * its jobs has settled successfully; any failure marks it "failed".
     *
     * The `provisioning` guard means later jobs (deploys, env writes, PHP
     * switches) on an already-active app never reopen its lifecycle.
     */
    private function syncApplicationProvisioning(AgentJob $job): void
    {
        $application = $job->application;
        if ($application === null || $application->status !== 'provisioning') {
            return;
        }

        if ($job->status === AgentJob::STATUS_FAILED || $job->status === AgentJob::STATUS_TIMEOUT) {
            $application->forceFill(['status' => 'failed'])->save();

            return;
        }

        $unfinished = $application->server->agentJobs()
            ->where('application_id', $application->id)
            ->whereIn('status', [
                AgentJob::STATUS_PENDING,
                AgentJob::STATUS_DISPATCHED,
                AgentJob::STATUS_RUNNING,
            ])
            ->exists();

        if (! $unfinished) {
            $application->forceFill(['status' => 'active'])->save();
        }
    }

    /**
     * Advance a phased provisioning batch. When every job in the just-finished
     * job's phase has settled, dispatch the next phase's jobs together (marking
     * their services installing). If the whole phase failed, halt the batch and
     * mark the downstream steps not installed.
     */
    private function advancePhase(AgentJob $job): void
    {
        if (! $job->isPhaseComplete()) {
            return;
        }

        if (! $job->phaseHadSuccess()) {
            foreach ($job->laterPhasePendingJobs() as $skipped) {
                $skipped->markFailed(null, 'Skipped — provisioning halted after a failed phase');
                $this->serviceManager->setUnitsStatus(
                    $skipped->server,
                    $this->serviceManager->serviceNamesForJobLabel((string) $skipped->label),
                    ServiceManager::STATUS_NOT_INSTALLED,
                );
            }

            return;
        }

        foreach ($job->nextPhaseJobs() as $next) {
            $this->dispatcher->dispatchPending($next);
            // Mark its service installing the moment it's dispatched, not only
            // once output arrives, so the UI never shows a dispatched step as
            // "waiting".
            $this->serviceManager->setUnitsStatus(
                $next->server,
                $this->serviceManager->serviceNamesForJobLabel((string) $next->label),
                ServiceManager::STATUS_INSTALLING,
            );
        }
    }

    /**
     * Handle a metrics envelope: persist a ServerMetric row and prune old records.
     */
    private function handleMetrics(array $env): void
    {
        $serverId = $env['server_id'] ?? null;
        $server = $serverId ? Server::where('uuid', $serverId)->first() : null;
        if (! $server) {
            return;
        }

        $body = is_array($env['payload'] ?? null) ? $env['payload'] : [];

        ServerMetric::create([
            'server_id' => $server->id,
            'cpu_percent' => (float) ($body['cpu_percent'] ?? 0),
            'mem_total' => (int) ($body['mem_total'] ?? 0),
            'mem_used' => (int) ($body['mem_used'] ?? 0),
            'disk_total' => (int) ($body['disk_total'] ?? 0),
            'disk_used' => (int) ($body['disk_used'] ?? 0),
            'load1' => (float) ($body['load1'] ?? 0),
            'uptime_seconds' => (int) ($body['uptime_seconds'] ?? 0),
            'recorded_at' => now(),
        ]);

        // Keep only the last 7 days (~20 160 readings at 30 s intervals per server).
        ServerMetric::where('server_id', $server->id)
            ->where('recorded_at', '<', now()->subDays(7))
            ->delete();

        // Roll up per-service CPU/memory so the Services page can show live
        // resource usage. Each entry is `{ name, cpu_percent, memory_usage }`;
        // services the agent didn't report on this cycle are left untouched.
        $services = is_array($body['services'] ?? null) ? $body['services'] : [];
        foreach ($services as $svc) {
            if (! is_array($svc) || ! ($svc['name'] ?? null)) {
                continue;
            }
            Service::where('server_id', $server->id)
                ->where('name', $svc['name'])
                ->update([
                    'cpu_percent' => isset($svc['cpu_percent']) ? (float) $svc['cpu_percent'] : null,
                    'memory_usage' => isset($svc['memory_usage']) ? (int) $svc['memory_usage'] : null,
                ]);
        }

        // Check metrics against alert thresholds
        app(ThresholdChecker::class)->check($server, $body);
    }

    /**
     * Handle a sysinfo envelope: auto-fill empty server fields from agent-reported data.
     *
     * Fields are only written if the current DB value is null/empty, so user-provided
     * values are never overwritten.
     */
    private function handleSysinfo(array $env): void
    {
        $serverId = $env['server_id'] ?? null;
        $server = $serverId ? Server::where('uuid', $serverId)->first() : null;
        if (! $server) {
            return;
        }

        $body = is_array($env['payload'] ?? null) ? $env['payload'] : [];
        $changed = false;

        foreach (['hostname', 'private_ip', 'public_ip', 'os'] as $field) {
            $value = isset($body[$field]) ? (string) $body[$field] : null;
            if ($value && empty($server->$field)) {
                $server->$field = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $server->save();
        }
    }

    /**
     * Handle one event from the presence channel (online/offline transition).
     *
     * First connect  (no prior jobs, no services) → auto-provision core stack + seed records.
     * Reconnect      (has jobs, no services yet)  → probe to detect already-installed units.
     */
    public function handlePresence(string $payload): void
    {
        $ev = json_decode($payload, true);
        if (! is_array($ev)) {
            return;
        }

        $serverId = $ev['server_id'] ?? null;
        $server = $serverId ? Server::where('uuid', $serverId)->first() : null;
        if (! $server) {
            return;
        }

        $online = ($ev['status'] ?? null) === GatewayProtocol::STATUS_ONLINE;

        $server->forceFill([
            'status' => $online ? 'online' : 'offline',
            'last_seen_at' => now(),
            'agent_version' => $ev['agent_version'] ?? $server->agent_version,
        ])->save();

        if ($online) {
            $hasServices = $server->services()->where('type', 'systemd')->exists();
            $hasJobs = $server->agentJobs()->exists();

            if (! $hasServices && ! $hasJobs) {
                // Brand-new server: auto-provision core stack + any databases chosen at registration.
                $components = array_values(array_unique(array_merge(
                    self::AUTO_COMPONENTS,
                    $server->db_components ?? [],
                )));
                $this->provisionService->provision(
                    $server,
                    $components,
                    ['php_versions' => self::AUTO_PHP_VERSIONS],
                );
                $this->serviceManager->seedForServer(
                    $server,
                    $components,
                    self::AUTO_PHP_VERSIONS,
                );
            } elseif (! $hasServices) {
                // Server reconnected but services were never seeded — probe what's installed.
                $this->dispatcher->dispatch($server, 'shell', [
                    'command' => $this->serviceManager->probeCommand(),
                    'timeout' => 60,
                ], ['label' => ServiceManager::PROBE_LABEL]);
            }

            // Re-deliver jobs that were dispatched but never picked up — e.g. lost
            // to a transient gateway pub/sub gap. Only those stuck for a while, so
            // we don't re-run jobs the agent is actively processing. This resumes a
            // sequential batch whose current step's dispatch was dropped.
            $stuck = $server->agentJobs()
                ->where('status', AgentJob::STATUS_DISPATCHED)
                ->whereNotNull('dispatched_at')
                ->where('dispatched_at', '<', now()->subSeconds(60))
                ->orderBy('id')
                ->get();
            foreach ($stuck as $stuckJob) {
                $this->dispatcher->dispatchPending($stuckJob);
            }
        }

        event(new ServerPresenceUpdated($server->refresh()));
    }
}
