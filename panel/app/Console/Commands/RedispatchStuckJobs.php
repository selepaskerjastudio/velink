<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use App\Models\Server;
use App\Services\JobDispatcher;
use Illuminate\Console\Command;

/**
 * Re-delivers agent jobs that are stuck in `dispatched` — published to the
 * gateway but never picked up by the agent (e.g. lost to a transient pub/sub
 * gap). Only targets ONLINE servers, since an offline agent can't receive the
 * job anyway. Safe to run on a schedule: provisioning steps are idempotent and
 * re-publishing only touches jobs that have been stuck past the threshold.
 */
class RedispatchStuckJobs extends Command
{
    protected $signature = 'jobs:redispatch-stuck
        {--older-than=2 : only re-dispatch jobs stuck (dispatched) for at least this many minutes}
        {--server= : optional server UUID to scope to}';

    protected $description = 'Re-publish agent jobs stuck in the dispatched state to online servers';

    public function handle(JobDispatcher $dispatcher): int
    {
        $minutes = max(0, (int) $this->option('older-than'));
        $cutoff = now()->subMinutes($minutes);

        $query = AgentJob::query()
            ->where('agent_jobs.status', AgentJob::STATUS_DISPATCHED)
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $cutoff)
            // Only servers whose agent is currently connected.
            ->whereHas('server', fn ($q) => $q->where('status', 'online'));

        if ($uuid = $this->option('server')) {
            $server = Server::where('uuid', $uuid)->first();
            if (! $server) {
                $this->error("Server not found: {$uuid}");

                return self::FAILURE;
            }
            $query->where('server_id', $server->id);
        }

        $jobs = $query->orderBy('id')->get();

        if ($jobs->isEmpty()) {
            $this->info('No stuck jobs to re-dispatch.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $dispatcher->dispatchPending($job);
            $this->line("re-dispatched #{$job->id} [{$job->label}] on server {$job->server->uuid}");
        }

        $this->info("Re-dispatched {$jobs->count()} stuck job(s).");

        return self::SUCCESS;
    }
}
