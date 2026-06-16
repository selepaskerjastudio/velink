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
     * The next not-yet-run step in this job's batch (the one to dispatch after
     * this one succeeds), or null if this is the last step or not in a batch.
     */
    public function nextInBatch(): ?self
    {
        if ($this->batch_id === null) {
            return null;
        }

        return self::where('batch_id', $this->batch_id)
            ->where('status', self::STATUS_PENDING)
            ->where('batch_sequence', '>', $this->batch_sequence)
            ->orderBy('batch_sequence')
            ->first();
    }

    /**
     * Remaining not-yet-run steps in this job's batch after this one — used to
     * halt the batch when a step fails so they don't sit pending forever.
     *
     * @return Collection<int, self>
     */
    public function remainingInBatch(): Collection
    {
        if ($this->batch_id === null) {
            return self::whereRaw('1 = 0')->get();
        }

        return self::where('batch_id', $this->batch_id)
            ->where('status', self::STATUS_PENDING)
            ->where('batch_sequence', '>', $this->batch_sequence)
            ->orderBy('batch_sequence')
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
