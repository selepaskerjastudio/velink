<?php

namespace App\Models;

use App\Contracts\BelongsToServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model implements BelongsToServer
{
    protected $fillable = [
        'server_id',
        'application_id',
        'type',
        'name',
        'command',
        'status',
        'config',
        'cpu_percent',
        'memory_usage',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
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
