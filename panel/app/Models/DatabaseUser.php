<?php

namespace App\Models;

use App\Contracts\BelongsToServer;
use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model implements BelongsToServer
{
    use HasUuidRouteKey;

    protected $fillable = [
        'server_id',
        'uuid',
        'engine',
        'username',
        'password',
        'host',
        'grants',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'grants' => 'array',
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
}
