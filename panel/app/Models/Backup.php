<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasUuidRouteKey;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'application_id',
        'server_id',
        'status',
        'type',
        'size_bytes',
        'storage',
        'local_path',
        's3_key',
        'message',
        'agent_job_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Application, self>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<Server, self>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
