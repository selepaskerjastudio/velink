<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Deletes "stuck" agent jobs — domain jobs left in `dispatched`/`pending` that
 * will never progress because the agent never executed them (e.g. a pub/sub
 * delivery gap). This is a cleanup utility, distinct from the normal lifecycle.
 */
class PurgeStuckJobs extends Command
{
    protected $signature = 'jobs:purge-stuck
        {--status=dispatched,pending : comma-separated statuses to purge}
        {--older-than=5 : only purge jobs whose dispatched_at (or created_at if null) is older than this many minutes; 0 = no age filter}
        {--server= : optional server UUID to scope to}
        {--force : skip the confirmation prompt}';

    protected $description = 'Delete stuck agent jobs (dispatched/pending that never progressed)';

    public function handle(): int
    {
        $statuses = collect(explode(',', (string) $this->option('status')))
            ->map(fn (string $s): string => trim($s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($statuses)) {
            $this->error('No statuses provided to --status.');

            return self::FAILURE;
        }

        $query = AgentJob::query()->whereIn('status', $statuses);

        if ($serverUuid = $this->option('server')) {
            $server = Server::query()->where('uuid', $serverUuid)->first();

            if (! $server) {
                $this->error("Server not found for uuid [{$serverUuid}].");

                return self::FAILURE;
            }

            $query->where('server_id', $server->id);
            $this->line("Scoped to server [{$server->uuid}].");
        }

        $olderThan = (int) $this->option('older-than');

        if ($olderThan > 0) {
            $cutoff = now()->subMinutes($olderThan);
            $query->where(function ($q) use ($cutoff): void {
                $q->where('dispatched_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff): void {
                        $q->whereNull('dispatched_at')->where('created_at', '<', $cutoff);
                    });
            });
            $this->line("Age filter: older than {$olderThan} minute(s) (before {$cutoff->toDateTimeString()}).");
        } else {
            $this->line('Age filter: none (--older-than=0).');
        }

        $count = (clone $query)->count();

        $this->info("Found {$count} stuck job(s) with status [".implode(', ', $statuses).'].');

        if ($count === 0) {
            $this->line('Nothing to purge.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} stuck job(s)?", false)) {
            $this->warn('Aborted. No jobs deleted.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} stuck job(s).");

        return self::SUCCESS;
    }
}
