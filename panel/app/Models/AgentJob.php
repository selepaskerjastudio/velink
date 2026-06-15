<?php

namespace App\Models;

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
        'server_id',
        'application_id',
        'user_id',
        'type',
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
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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
