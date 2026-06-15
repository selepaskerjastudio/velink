<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\PhpPool;
use App\Provisioning\AppTemplates;

/**
 * Provisions the per-application Linux user, php-fpm pool, and nginx vhost,
 * and handles switching an application's PHP version with minimal downtime.
 *
 * The php-fpm socket is keyed by linux_user only (see AppTemplates), so a
 * version switch never touches the nginx vhost.
 */
class AppProvisionService
{
    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * @return array<int, AgentJob>
     */
    public function provisionNew(Application $app, ?int $userId = null): array
    {
        $vars = AppTemplates::vars($app);
        $jobs = [];

        $jobs[] = $this->shell($app, 'Create Linux user', <<<SH
            id -u {$app->linux_user} >/dev/null 2>&1 || useradd --create-home --shell /usr/sbin/nologin {$app->linux_user}
            SH, $userId);

        $jobs[] = $this->shell($app, 'Create app directory', <<<SH
            mkdir -p {$app->root_path}/public {$app->root_path}/tmp
            if [ ! -f {$app->root_path}/public/index.php ]; then
                printf '%s\\n' '<?php' 'phpinfo();' > {$app->root_path}/public/index.php
            fi
            chown -R {$app->linux_user}:{$app->linux_user} {$app->root_path}
            chmod 750 {$app->root_path}
            SH, $userId);

        $jobs[] = $this->renderConfig(
            $app,
            'Write PHP-FPM pool config',
            AppTemplates::poolConfigPath($app->php_version, $app->linux_user),
            AppTemplates::PHP_FPM_POOL,
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Reload PHP-FPM', <<<SH
            systemctl reload php{$app->php_version}-fpm
            SH, $userId);

        $jobs[] = $this->renderConfig(
            $app,
            'Write nginx vhost',
            AppTemplates::vhostPath((string) $app->domain),
            AppTemplates::NGINX_VHOST,
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Enable site & reload nginx', <<<SH
            ln -sf {$this->path(AppTemplates::vhostPath((string) $app->domain))} {$this->path(AppTemplates::vhostEnabledPath((string) $app->domain))}
            nginx -t
            systemctl reload nginx
            SH, $userId);

        PhpPool::create([
            'application_id' => $app->id,
            'php_version' => $app->php_version,
            'pool_name' => $app->linux_user,
            'socket_path' => $vars['socket_path'],
            'config' => $vars,
        ]);

        return $jobs;
    }

    /**
     * Move the php-fpm pool config from the old version's directory to the
     * new one and reload both daemons. The socket path (and therefore the
     * nginx vhost) is unchanged.
     *
     * @return array<int, AgentJob>
     */
    public function changePhpVersion(Application $app, string $newVersion, ?int $userId = null): array
    {
        $oldVersion = $app->php_version;
        $vars = AppTemplates::vars($app);
        $jobs = [];

        $jobs[] = $this->shell($app, 'Remove old PHP-FPM pool', <<<SH
            rm -f {$this->path(AppTemplates::poolConfigPath($oldVersion, $app->linux_user))}
            systemctl reload php{$oldVersion}-fpm || true
            SH, $userId);

        $jobs[] = $this->renderConfig(
            $app,
            "Write PHP-FPM pool config (PHP {$newVersion})",
            AppTemplates::poolConfigPath($newVersion, $app->linux_user),
            AppTemplates::PHP_FPM_POOL,
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Reload PHP-FPM', <<<SH
            systemctl reload php{$newVersion}-fpm
            SH, $userId);

        $app->forceFill(['php_version' => $newVersion])->save();

        $pool = $app->phpPools()->where('pool_name', $app->linux_user)->first();
        if ($pool !== null) {
            $pool->forceFill(['php_version' => $newVersion, 'config' => $vars])->save();
        } else {
            PhpPool::create([
                'application_id' => $app->id,
                'php_version' => $newVersion,
                'pool_name' => $app->linux_user,
                'socket_path' => $vars['socket_path'],
                'config' => $vars,
            ]);
        }

        return $jobs;
    }

    private function shell(Application $app, string $label, string $command, ?int $userId): AgentJob
    {
        $lines = array_map('trim', explode("\n", trim($command)));

        return $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => "set -e\necho \"==> {$label}\"\n".implode("\n", $lines),
            'timeout' => 120,
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => $label]);
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function renderConfig(Application $app, string $label, string $path, string $template, array $vars, ?int $userId): AgentJob
    {
        return $this->dispatcher->dispatch($app->server, 'render_config', [
            'path' => $path,
            'template' => $template,
            'vars' => $vars,
            'mode' => '0644',
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => $label]);
    }

    /**
     * Quote a filesystem path for safe interpolation into shell heredocs.
     */
    private function path(string $path): string
    {
        return escapeshellarg($path);
    }
}
