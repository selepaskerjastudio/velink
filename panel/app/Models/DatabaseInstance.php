<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseInstance extends Model
{
    use HasUuidRouteKey;

    protected $table = 'databases';

    protected $fillable = [
        'server_id',
        'uuid',
        'engine',
        'name',
        'charset',
        'collation',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
