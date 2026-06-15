<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    /** No created_at / updated_at — timeseries, append-only. */
    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'cpu_percent',
        'mem_total',
        'mem_used',
        'disk_total',
        'disk_used',
        'load1',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
