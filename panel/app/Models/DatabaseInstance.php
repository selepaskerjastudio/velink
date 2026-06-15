<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseInstance extends Model
{
    protected $table = 'databases';

    protected $fillable = [
        'server_id',
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
