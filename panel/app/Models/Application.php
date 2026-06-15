<?php

namespace App\Models;

use App\Models\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Application extends Model
{
    use HasFactory;
    use HasUuidRouteKey;

    protected $fillable = [
        'uuid',
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

    public function activePhpPool(): ?PhpPool
    {
        return $this->phpPools()->where('php_version', $this->php_version)->first();
    }

    /**
     * Derive a unique, valid Linux username (lowercase letters, digits,
     * underscores; starts with a letter; <= 32 chars) from a domain/name,
     * unique per server.
     */
    public static function generateLinuxUser(int $serverId, string $seed): string
    {
        $slug = Str::slug($seed, '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';

        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = 'app_'.$slug;
        }

        $base = substr($slug, 0, 28);
        $candidate = $base;
        $suffix = 1;

        while (static::where('server_id', $serverId)->where('linux_user', $candidate)->exists()) {
            $suffix++;
            $candidate = substr($base, 0, 28 - strlen((string) $suffix) - 1)."_{$suffix}";
        }

        return $candidate;
    }
}
