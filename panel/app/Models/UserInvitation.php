<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'email',
        'role',
        'token',
        'server_ids',
        'invited_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'server_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Hash a plaintext invite token for storage/lookup. Stored as a plain
     * sha256 hex digest rather than a bcrypt `hashed` cast — accepting an
     * invite requires a `where('token', ...)` lookup by the plaintext link,
     * which a one-way bcrypt hash makes impossible.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
