<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Support\GatewayProtocol;
use Illuminate\Support\Facades\Redis;

/**
 * Creates AgentJobs and publishes them onto the gateway dispatch channel for
 * delivery to the target server's agent.
 */
class JobDispatcher
{
    /**
     * @param  array<string, mixed>  $params      Action-specific parameters (the executor's input).
     * @param  array{application_id?: int|null, user_id?: int|null}  $attributes
     */
    public function dispatch(Server $server, string $type, array $params = [], array $attributes = []): AgentJob
    {
        $job = AgentJob::create([
            'server_id' => $server->id,
            'application_id' => $attributes['application_id'] ?? null,
            'user_id' => $attributes['user_id'] ?? null,
            'type' => $type,
            'payload' => $params,
            'status' => AgentJob::STATUS_PENDING,
        ]);

        $this->publish($job);
        $job->markDispatched();

        return $job;
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
            'server_id' => $job->server_id,
            'payload' => [
                'action' => $job->type,
                'params' => $job->payload ?? [],
            ],
            'ts' => (int) round(microtime(true) * 1000),
        ];
    }
}
