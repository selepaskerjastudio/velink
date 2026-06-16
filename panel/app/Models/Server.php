<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'name',
        'hostname',
        'public_ip',
        'private_ip',
        'os',
        'status',
        'agent_token',
        'agent_version',
        'last_seen_at',
        'resources',
        'db_components',
    ];

    protected $hidden = [
        'agent_token',
    ];

    protected function casts(): array
    {
        return [
            'agent_token' => 'hashed',
            'last_seen_at' => 'datetime',
            'resources' => 'array',
            'db_components' => 'array',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(DatabaseInstance::class);
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function latestMetric()
    {
        return $this->hasOne(ServerMetric::class)->latestOfMany();
    }
}
