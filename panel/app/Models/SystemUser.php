<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SystemUser extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'server_id',
        'username',
        'shell',
        'is_sudo',
        'is_system_reserved',
    ];

    protected $casts = [
        'is_sudo' => 'boolean',
        'is_system_reserved' => 'boolean',
    ];

    /**
     * @return BelongsTo<Server, self>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The SSH keys deployed to this user's authorized_keys.
     *
     * @return BelongsToMany<SshKey>
     */
    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class, 'server_ssh_key')
            ->withPivot('deployed_at')
            ->withTimestamps();
    }
}
