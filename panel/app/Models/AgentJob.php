<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A unit of work dispatched to an agent on a managed server. Distinct from the
 * Laravel queue `jobs` table — this is the domain Job that travels panel ->
 * gateway -> agent and reports progress back.
 *
 * Lifecycle: pending -> dispatched -> running -> succeeded | failed | timeout
 */
class AgentJob extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    protected $fillable = [
        'uuid',
        'batch_id',
        'batch_sequence',
        'server_id',
        'application_id',
        'user_id',
        'type',
        'label',
        'payload',
        'status',
        'exit_code',
        'output',
        'error',
        'dispatched_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'exit_code' => 'integer',
            'batch_sequence' => 'integer',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Terminal (finished) states a batch step can settle into.
     */
    public const TERMINAL_STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_TIMEOUT];

    /**
     * Batches run in phases: `batch_sequence` is the phase number. All jobs in
     * a phase run concurrently; the next phase is dispatched only once every job
     * in the current phase has finished. Dependencies are encoded as phases
     * (base → everything; the PHP PPA → PHP installs → composer).
     *
     * True when every job in this job's phase has reached a terminal state.
     */
    public function isPhaseComplete(): bool
    {
        if ($this->batch_id === null) {
            return false;
        }

        return ! self::where('batch_id', $this->batch_id)
            ->where('batch_sequence', $this->batch_sequence)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->exists();
    }

    /** True when at least one job in this job's phase succeeded. */
    public function phaseHadSuccess(): bool
    {
        return self::where('batch_id', $this->batch_id)
            ->where('batch_sequence', $this->batch_sequence)
            ->where('status', self::STATUS_SUCCEEDED)
            ->exists();
    }

    /**
     * The pending jobs that make up the next phase (lowest phase number greater
     * than this job's), to be dispatched together.
     *
     * @return Collection<int, self>
     */
    public function nextPhaseJobs(): Collection
    {
        if ($this->batch_id === null) {
            return self::whereRaw('1 = 0')->get();
        }

        $nextPhase = self::where('batch_id', $this->batch_id)
            ->where('batch_sequence', '>', $this->batch_sequence)
            ->where('status', self::STATUS_PENDING)
            ->min('batch_sequence');

        if ($nextPhase === null) {
            return self::whereRaw('1 = 0')->get();
        }

        return self::where('batch_id', $this->batch_id)
            ->where('batch_sequence', $nextPhase)
            ->where('status', self::STATUS_PENDING)
            ->get();
    }

    /**
     * All still-pending jobs in later phases — marked failed when the batch is
     * halted because a whole phase failed.
     *
     * @return Collection<int, self>
     */
    public function laterPhasePendingJobs(): Collection
    {
        if ($this->batch_id === null) {
            return self::whereRaw('1 = 0')->get();
        }

        return self::where('batch_id', $this->batch_id)
            ->where('batch_sequence', '>', $this->batch_sequence)
            ->where('status', self::STATUS_PENDING)
            ->get();
    }

    protected static function booted(): void
    {
        static::creating(function (AgentJob $job): void {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_TIMEOUT,
        ], true);
    }

    public function markDispatched(): void
    {
        $this->forceFill([
            'status' => self::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ])->save();
    }

    public function markRunning(): void
    {
        if ($this->status === self::STATUS_RUNNING) {
            return;
        }
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function appendOutput(string $chunk): void
    {
        $this->forceFill([
            'output' => ($this->output ?? '').$chunk,
        ])->save();
    }

    public function markSucceeded(int $exitCode = 0): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'exit_code' => $exitCode,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(?int $exitCode = null, ?string $error = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'exit_code' => $exitCode,
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }
}
