<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'application_id',
        'cloudflare_token_id',
        'zone_id',
        'record_id',
        'type',
        'name',
        'content',
        'proxied',
        'ttl',
    ];

    protected $casts = [
        'proxied' => 'boolean',
        'ttl' => 'integer',
    ];

    /**
     * @return BelongsTo<Application, self>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<CloudflareToken, self>
     */
    public function cloudflareToken(): BelongsTo
    {
        return $this->belongsTo(CloudflareToken::class);
    }
}
