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

    /**
     * Supported application types. Drives the directory layout, which nginx
     * vhost template is rendered, whether a php-fpm pool is provisioned, and
     * the default deploy script.
     */
    public const APP_TYPES = ['custom', 'laravel', 'wordpress', 'static'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->webhook_secret)) {
                $model->webhook_secret = Str::random(40);
            }
        });
    }

    protected $fillable = [
        'uuid',
        'server_id',
        'git_credential_id',
        'name',
        'domain',
        'root_path',
        'linux_user',
        'app_slug',
        'php_version',
        'app_type',
        'stack_mode',
        'repository',
        'branch',
        'deploy_mode',
        'deploy_script',
        'env_content',
        'status',
        'webhook_secret',
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
     * Static sites are served straight from disk by nginx and get no
     * php-fpm pool; every other type runs PHP.
     */
    public function usesPhp(): bool
    {
        return $this->app_type !== 'static';
    }

    /**
     * Derive a unique, filesystem/pool-safe slug (lowercase letters, digits,
     * underscores; starts with a letter; <= 32 chars) from a domain/name,
     * unique per server. Drives the webapp folder, the php-fpm pool name +
     * socket, the pool conf filename, and the nginx log filenames.
     */
    public static function generateAppSlug(int $serverId, string $seed): string
    {
        $slug = Str::slug($seed, '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';

        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = 'app_'.$slug;
        }

        $base = substr($slug, 0, 28);
        $candidate = $base;
        $suffix = 1;

        while (static::where('server_id', $serverId)->where('app_slug', $candidate)->exists()) {
            $suffix++;
            $candidate = substr($base, 0, 28 - strlen((string) $suffix) - 1)."_{$suffix}";
        }

        return $candidate;
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
