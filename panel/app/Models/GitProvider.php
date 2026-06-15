<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitProvider extends Model
{
    protected $fillable = [
        'type',
        'name',
        'base_url',
        'client_id',
        'client_secret',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(GitCredential::class);
    }
}
