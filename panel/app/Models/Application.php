<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    protected $fillable = [
        'server_id',
        'git_credential_id',
        'name',
        'domain',
        'root_path',
        'linux_user',
        'php_version',
        'repository',
        'branch',
        'deploy_mode',
        'deploy_script',
        'env_content',
        'status',
    ];

    protected $hidden = [
        'env_content',
    ];

    protected function casts(): array
    {
        return [
            'env_content' => 'encrypted',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function gitCredential(): BelongsTo
    {
        return $this->belongsTo(GitCredential::class);
    }

    public function phpPools(): HasMany
    {
        return $this->hasMany(PhpPool::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }
}
