<?php

namespace App\Listeners;

use App\Events\AgentJobUpdated;
use App\Models\AgentJob;
use App\Models\Deployment;

/**
 * Mirrors an AgentJob's progress onto the Deployment record it was created
 * for, so the deployment history shows live status/log without a separate
 * broadcast channel.
 */
class SyncDeploymentFromAgentJob
{
    public function handle(AgentJobUpdated $event): void
    {
        $job = $event->job;

        $deployment = Deployment::where('agent_job_uuid', $job->uuid)->first();
        if ($deployment === null) {
            return;
        }

        $deployment->forceFill([
            'log' => $job->output,
            'status' => $this->status($job),
            'finished_at' => $job->isTerminal() ? ($job->finished_at ?? now()) : $deployment->finished_at,
        ])->save();

        // A successful deploy recovers an app stuck in `failed` — e.g. an early
        // deploy that ran before the stack was ready. The provisioning lifecycle
        // in GatewayInboundProcessor is one-way (it only acts while the app is
        // `provisioning`), so without this a since-fixed app would display
        // `failed` forever.
        if ($job->status === AgentJob::STATUS_SUCCEEDED) {
            $application = $deployment->application;
            if ($application !== null && $application->status === 'failed') {
                $application->forceFill(['status' => 'active'])->save();
            }
        }
    }

    private function status(AgentJob $job): string
    {
        return match ($job->status) {
            AgentJob::STATUS_SUCCEEDED => 'success',
            AgentJob::STATUS_FAILED, AgentJob::STATUS_TIMEOUT => 'failed',
            default => 'running',
        };
    }
}
