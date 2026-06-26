<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudflareToken extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'user_id',
        'email',
        'api_token',
        'verified_at',
    ];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DnsRecord>
     */
    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }
}
