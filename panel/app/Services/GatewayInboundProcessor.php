<?php

namespace App\Services;

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\ServerMetric;
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
     * Handle one envelope from the inbound channel (job output / result / metrics).
     */
    public function handleInbound(string $payload): void
    {
        $env = json_decode($payload, true);
        if (! is_array($env)) {
            return;
        }

        // Metrics have no job_id — handle them before the job_id check.
        if (($env['type'] ?? '') === GatewayProtocol::TYPE_METRICS) {
            $this->handleMetrics($env);

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
                $job->markRunning();
                $chunk = (string) ($body['data'] ?? '');
                if ($chunk !== '') {
                    $job->appendOutput($chunk);
                }
                break;

            case GatewayProtocol::TYPE_JOB_RESULT:
                $exit = (int) ($body['exit_code'] ?? 0);
                if ($exit === 0) {
                    $job->markSucceeded($exit);
                } else {
                    $job->markFailed($exit, $body['error'] ?? null);
                }
                break;

            default:
                return;
        }

        event(new AgentJobUpdated($job->refresh()));
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
            'server_id'   => $server->id,
            'cpu_percent' => (float) ($body['cpu_percent'] ?? 0),
            'mem_total'   => (int) ($body['mem_total'] ?? 0),
            'mem_used'    => (int) ($body['mem_used'] ?? 0),
            'disk_total'  => (int) ($body['disk_total'] ?? 0),
            'disk_used'   => (int) ($body['disk_used'] ?? 0),
            'load1'       => (float) ($body['load1'] ?? 0),
            'recorded_at' => now(),
        ]);

        // Keep only the last 2 hours (~240 readings at 30 s intervals).
        ServerMetric::where('server_id', $server->id)
            ->where('recorded_at', '<', now()->subHours(2))
            ->delete();
    }

    /**
     * Handle one event from the presence channel (online/offline transition).
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

        event(new ServerPresenceUpdated($server->refresh()));
    }
}
