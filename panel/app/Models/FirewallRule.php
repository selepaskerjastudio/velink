<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
        'server_id',
        'protocol',
        'port',
        'action',
        'source',
        'is_protected',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
        'port' => 'integer',
    ];

    /**
     * @return BelongsTo<Server, self>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
