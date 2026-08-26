<?php

namespace App\Models;

use App\Contracts\BelongsToServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model implements BelongsToServer
{
    protected $fillable = [
        'server_id',
        'application_id',
        'user',
        'command',
        'schedule',
        'status',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function owningServer(): ?Server
    {
        return $this->server;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
