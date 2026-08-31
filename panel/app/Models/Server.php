<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;
    use HasUuidRouteKey;

    /** Maps the DB systemd unit name to the engine key used across the UI. */
    private const ENGINE_UNITS = [
        'mariadb' => 'mariadb',
        'postgresql' => 'postgres',
        'mongod' => 'mongodb',
    ];

    protected $fillable = [
        'uuid',
        'name',
        'hostname',
        'public_ip',
        'private_ip',
        'uses_edge_proxy',
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
            'uses_edge_proxy' => 'boolean',
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

    /**
     * The SSH keys deployed to this server's authorized_keys.
     *
     * @return BelongsToMany<SshKey>
     */
    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'server_ssh_key')
            ->withPivot('deployed_at', 'system_user_id')
            ->withTimestamps();
    }

    /**
     * The OS login accounts managed on this server.
     *
     * @return HasMany<SystemUser>
     */
    public function systemUsers(): HasMany
    {
        return $this->hasMany(SystemUser::class);
    }

    /**
     * @return HasMany<FirewallRule>
     */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    public function latestMetric()
    {
        return $this->hasOne(ServerMetric::class)->latestOfMany();
    }

    /**
     * Users assigned access to this server.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Scope to servers visible to the given user. Admins see everything,
     * including servers nobody has been assigned to; members see only
     * their assigned servers.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('servers.id', $user->servers()->select('servers.id'));
    }

    /**
     * The database engine keys (mariadb|postgres|mongodb) whose systemd unit is
     * currently running on this server. Used to gate DB-related UI.
     *
     * @return list<string>
     */
    public function installedDatabaseEngines(): array
    {
        $unitStatuses = $this->services()
            ->whereIn('name', array_keys(self::ENGINE_UNITS))
            ->pluck('status', 'name');

        $installedEngines = [];
        foreach (self::ENGINE_UNITS as $unit => $engine) {
            if (in_array($unitStatuses[$unit] ?? null, ['running', 'active'], true)) {
                $installedEngines[] = $engine;
            }
        }

        return $installedEngines;
    }
}
