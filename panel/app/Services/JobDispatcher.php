<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Support\GatewayProtocol;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Creates AgentJobs and publishes them onto the gateway dispatch channel for
 * delivery to the target server's agent.
 */
class JobDispatcher
{
    private const ALLOWED_ACTIONS = ['shell', 'write_file', 'render_config'];

    /**
     * @param  array<string, mixed>  $params  Action-specific parameters (the executor's input).
     * @param  array{application_id?: int|null, user_id?: int|null, label?: string|null}  $attributes
     */
    public function dispatch(Server $server, string $type, array $params = [], array $attributes = []): AgentJob
    {
        if (! in_array($type, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown agent job action: '{$type}'. Allowed: ".implode(', ', self::ALLOWED_ACTIONS));
        }

        $job = AgentJob::create([
            'server_id' => $server->id,
            'application_id' => $attributes['application_id'] ?? null,
            'user_id' => $attributes['user_id'] ?? null,
            'type' => $type,
            'label' => $attributes['label'] ?? null,
            'payload' => $params,
            'status' => AgentJob::STATUS_PENDING,
        ]);

        $this->publish($job);
        $job->markDispatched();

        return $job;
    }

    /**
     * Queue a list of phased steps as a single batch. Each step carries a
     * `phase` (stored as `batch_sequence`); all steps in a phase run
     * concurrently, and the next phase is dispatched by GatewayInboundProcessor
     * only once every job in the current phase has finished. This parallelises
     * independent installs while still ordering dependencies (base → the rest;
     * PPA → PHP installs → composer).
     *
     * Only the lowest phase is dispatched now; the rest wait as `pending`.
     *
     * @param  array<int, array{name?: string, type: string, phase?: int, params: array<string, mixed>}>  $steps
     * @return array<int, AgentJob>
     */
    public function queueBatch(Server $server, array $steps, ?int $userId = null): array
    {
        $batchId = (string) Str::uuid();
        $jobs = [];

        foreach (array_values($steps) as $step) {
            if (! in_array($step['type'], self::ALLOWED_ACTIONS, true)) {
                throw new \InvalidArgumentException("Unknown agent job action: '{$step['type']}'. Allowed: ".implode(', ', self::ALLOWED_ACTIONS));
            }

            $jobs[] = AgentJob::create([
                'batch_id' => $batchId,
                'batch_sequence' => $step['phase'] ?? 0,
                'server_id' => $server->id,
                'user_id' => $userId,
                'type' => $step['type'],
                'label' => $step['name'] ?? null,
                'payload' => $step['params'],
                'status' => AgentJob::STATUS_PENDING,
            ]);
        }

        if ($jobs !== []) {
            $minPhase = min(array_map(fn (AgentJob $j) => $j->batch_sequence, $jobs));
            foreach ($jobs as $job) {
                if ($job->batch_sequence === $minPhase) {
                    $this->dispatchPending($job);
                }
            }
        }

        return $jobs;
    }

    /**
     * Publish a job that was created as `pending` (e.g. the next phase of a
     * batch, or a stuck job being re-dispatched) and mark it dispatched. Safe to
     * call on an already-dispatched job to re-deliver it.
     */
    public function dispatchPending(AgentJob $job): void
    {
        $this->publish($job);
        $job->markDispatched();
    }

    public function publish(AgentJob $job): void
    {
        Redis::connection(GatewayProtocol::REDIS_CONNECTION)
            ->publish(GatewayProtocol::CHANNEL_DISPATCH, json_encode($this->buildEnvelope($job)));
    }

    /**
     * Build the wire envelope. The transport type is "job"; the executor action
     * and its parameters live in the nested payload.
     *
     * @return array<string, mixed>
     */
    public function buildEnvelope(AgentJob $job): array
    {
        return [
            'type' => GatewayProtocol::TYPE_JOB,
            'job_id' => $job->uuid,
            'server_id' => $job->server->uuid,
            'payload' => [
                'action' => $job->type,
                'params' => $job->payload ?? [],
            ],
            'ts' => (int) round(microtime(true) * 1000),
        ];
    }
}
