<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SshKey extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'public_key',
        'fingerprint',
        'type',
        'comment',
    ];

    /**
     * The servers this key has been deployed to.
     *
     * @return BelongsToMany<Server>
     */
    public function servers(): BelongsToMany
    {
        // Using withTimestamps() + the explicit deployed_at pivot column so
        // the deploy-time is queryable alongside created_at/updated_at.
        return $this->belongsToMany(Server::class, 'server_ssh_key')
            ->withPivot('deployed_at')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
