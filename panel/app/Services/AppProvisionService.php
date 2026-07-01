<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\PhpPool;
use App\Provisioning\AppTemplates;
use App\Provisioning\PhpSettings;

/**
 * Provisions a web application's directory, php-fpm pool, and nginx vhost on a
 * managed server, and handles switching its PHP version with minimal downtime.
 *
 * Every app shares one OS user (config `velink.webapp_user`) and lives in
 * /home/{user}/webapps/{app_slug}. The php-fpm socket + pool conf are keyed by
 * app_slug only (see AppTemplates), so a version switch never touches the
 * nginx vhost. Static sites get no php-fpm pool at all.
 *
 * Path components interpolated into the shell heredocs below are safe:
 *  - app_slug is generated from Str::slug (lowercase letters/digits/underscore,
 *    starts with a letter, <=28 chars) — no shell metacharacters.
 *  - the OS user comes from config, not user input.
 *  - domain is validated by ApplicationController's DOMAIN_REGEX upstream.
 */
class AppProvisionService
{
    public function __construct(private JobDispatcher $dispatcher) {}

    /**
     * @param  array{name: string, user: string, password: string, host?: string}|null  $dbCreds
     *                                                                                            WordPress DB credentials, used to render wp-config.php.
     * @return array<int, AgentJob>
     */
    public function provisionNew(Application $app, ?int $userId = null, ?array $dbCreds = null): array
    {
        $slug = AppTemplates::slug($app);
        $vars = AppTemplates::vars($app);
        $osUser = AppTemplates::webappUser();
        $home = AppTemplates::homeDir();
        $webapps = AppTemplates::webappsDir();
        $logsDir = AppTemplates::logsDir();
        $root = $app->root_path;
        $jobs = [];

        // 1. Ensure the shared OS user, the per-app directory, and the shared
        //    logs directory exist. The webapp user owns its home tree so it can
        //    SSH in and manage files directly (RunCloud model). Directories are
        //    world-traversable (755) so nginx/www-data can reach the web root.
        $jobs[] = $this->shell($app, 'Create web app directory', <<<SH
            id -u {$osUser} >/dev/null 2>&1 || useradd --create-home --shell /bin/bash {$osUser}
            mkdir -p {$home} {$webapps} {$root}/public {$root}/tmp {$logsDir}
            chown {$osUser}:{$osUser} {$home} {$webapps} {$logsDir}
            chmod 755 {$home} {$webapps}
            {$this->placeholder($app)}
            chown -R {$osUser}:{$osUser} {$root}
            chmod 755 {$root}
            SH, $userId);

        // 2. php-fpm pool (skipped for static sites).
        if ($app->usesPhp()) {
            $jobs[] = $this->renderConfig(
                $app,
                'Write PHP-FPM pool config',
                AppTemplates::poolConfigPath($app->php_version, $slug),
                AppTemplates::PHP_FPM_POOL,
                $vars,
                $userId,
            );

            $jobs[] = $this->shell($app, 'Reload PHP-FPM', <<<SH
                systemctl reload php{$app->php_version}-fpm
                SH, $userId);
        }

        // 3. nginx vhost (template depends on app type).
        $jobs[] = $this->renderConfig(
            $app,
            'Write nginx vhost',
            AppTemplates::vhostPath((string) $app->domain),
            AppTemplates::vhostTemplate($app->app_type),
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Enable site & reload nginx', <<<SH
            ln -sf {$this->path(AppTemplates::vhostPath((string) $app->domain))} {$this->path(AppTemplates::vhostEnabledPath((string) $app->domain))}
            nginx -t
            systemctl reload nginx
            SH, $userId);

        // 4. WordPress: download core into /public + render wp-config.php one
        //    level up in the root (outside the docroot; WordPress auto-loads it
        //    from the parent dir), wired to the DB created in the same flow.
        if ($app->app_type === 'wordpress' && $dbCreds !== null) {
            $jobs[] = $this->shell($app, 'Download WordPress core', <<<SH
                if [ ! -f {$root}/public/wp-settings.php ]; then
                    curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/{$slug}-wp.tar.gz
                    tar xzf /tmp/{$slug}-wp.tar.gz -C {$root}/public --strip-components=1
                    rm -f /tmp/{$slug}-wp.tar.gz
                    chown -R {$osUser}:{$osUser} {$root}
                fi
                SH, $userId);

            $jobs[] = $this->renderConfig(
                $app,
                'Write wp-config.php',
                "{$root}/wp-config.php",
                AppTemplates::WORDPRESS_WP_CONFIG,
                AppTemplates::wordpressVars($app, $dbCreds),
                $userId,
                '0640',
            );

            $jobs[] = $this->shell($app, 'Secure wp-config.php', <<<SH
                chown {$osUser}:{$osUser} {$root}/wp-config.php
                chmod 640 {$root}/wp-config.php
                SH, $userId);
        }

        if ($app->usesPhp()) {
            PhpPool::create([
                'application_id' => $app->id,
                'php_version' => $app->php_version,
                'pool_name' => $slug,
                'socket_path' => $vars['socket_path'],
                'config' => $vars,
            ]);
        }

        return $jobs;
    }

    /**
     * Tear down everything provisionNew created on the server: the nginx vhost
     * (+ enabled symlink), every php-fpm pool conf the app has owned, and the
     * web app directory. Jobs are best-effort (`|| true` / `rm -f`) so a
     * partially-provisioned or already-cleaned app still deletes cleanly. The
     * shared OS user is left intact since it is reused by other apps.
     *
     * @return array<int, AgentJob>
     */
    public function deprovision(Application $app, ?int $userId = null): array
    {
        $slug = AppTemplates::slug($app);
        $root = $app->root_path;
        $jobs = [];

        // 1. Remove the nginx vhost + enabled symlink, then reload (validating
        //    first so a stray config elsewhere doesn't take nginx down).
        $vhost = AppTemplates::vhostPath((string) $app->domain);
        $vhostEnabled = AppTemplates::vhostEnabledPath((string) $app->domain);
        $jobs[] = $this->shell($app, 'Remove nginx vhost', <<<SH
            rm -f {$this->path($vhostEnabled)} {$this->path($vhost)}
            nginx -t && systemctl reload nginx || true
            SH, $userId);

        // 2. Remove every php-fpm pool conf this app has owned (current version
        //    plus any left from past version switches) and reload those pools.
        $versions = $app->phpPools()->pluck('php_version')->push($app->php_version)->unique()->filter();
        foreach ($versions as $version) {
            $jobs[] = $this->shell($app, "Remove PHP-FPM pool (PHP {$version})", <<<SH
                rm -f {$this->path(AppTemplates::poolConfigPath($version, $slug))}
                systemctl reload php{$version}-fpm || true
                SH, $userId);
        }

        // 3. Remove the web app directory and its logs.
        $accessLog = AppTemplates::logPath($slug, 'access');
        $errorLog = AppTemplates::logPath($slug, 'error');
        $jobs[] = $this->shell($app, 'Remove web app directory', <<<SH
            rm -rf {$this->path($root)}
            rm -f {$this->path($accessLog)} {$this->path($errorLog)}
            SH, $userId);

        return $jobs;
    }

    /**
     * Move the php-fpm pool config from the old version's directory to the new
     * one and reload both daemons. The socket path (and therefore the nginx
     * vhost) is unchanged.
     *
     * @return array<int, AgentJob>
     */
    public function changePhpVersion(Application $app, string $newVersion, ?int $userId = null): array
    {
        $slug = AppTemplates::slug($app);
        $oldVersion = $app->php_version;
        $vars = AppTemplates::vars($app);
        $jobs = [];

        $jobs[] = $this->shell($app, 'Remove old PHP-FPM pool', <<<SH
            rm -f {$this->path(AppTemplates::poolConfigPath($oldVersion, $slug))}
            systemctl reload php{$oldVersion}-fpm || true
            SH, $userId);

        $jobs[] = $this->renderConfig(
            $app,
            "Write PHP-FPM pool config (PHP {$newVersion})",
            AppTemplates::poolConfigPath($newVersion, $slug),
            AppTemplates::PHP_FPM_POOL,
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Reload PHP-FPM', <<<SH
            systemctl reload php{$newVersion}-fpm
            SH, $userId);

        $app->forceFill(['php_version' => $newVersion])->save();

        $pool = $app->phpPools()->where('pool_name', $slug)->first();
        if ($pool !== null) {
            $pool->forceFill(['php_version' => $newVersion, 'config' => $vars])->save();
        } else {
            PhpPool::create([
                'application_id' => $app->id,
                'php_version' => $newVersion,
                'pool_name' => $slug,
                'socket_path' => $vars['socket_path'],
                'config' => $vars,
            ]);
        }

        return $jobs;
    }

    /**
     * Persist the per-app PHP-FPM / PHP ini settings and re-render the pool
     * conf for the active PHP version, then reload PHP-FPM. Mirrors the FPM
     * half of changePhpVersion() — the socket path (and therefore the nginx
     * vhost) is unchanged.
     *
     * @param  array<string, string>  $settings
     * @return array<int, AgentJob>
     */
    public function updatePhpSettings(Application $app, array $settings, ?int $userId = null): array
    {
        $slug = AppTemplates::slug($app);
        $version = $app->php_version;

        $app->forceFill(['php_settings' => $settings])->save();
        $vars = AppTemplates::vars($app);

        $jobs = [];
        $jobs[] = $this->renderConfig(
            $app,
            'Write PHP-FPM pool config',
            AppTemplates::poolConfigPath($version, $slug),
            AppTemplates::PHP_FPM_POOL,
            $vars,
            $userId,
        );

        $jobs[] = $this->shell($app, 'Reload PHP-FPM', <<<SH
            systemctl reload php{$version}-fpm
            SH, $userId);

        $pool = $app->phpPools()->where('pool_name', $slug)->first();
        if ($pool !== null) {
            $pool->forceFill(['config' => $vars])->save();
        }

        return $jobs;
    }

    /**
     * Change the app's domain: remove the old nginx vhost, render a new one
     * for the new domain, recreate the sites-enabled symlink, and reload nginx.
     *
     * If the new domain is null (static site with no domain), only removes the
     * old vhost. Also resets SSL state since the old cert is invalid.
     *
     * @return array<int, AgentJob>
     */
    public function changeDomain(Application $app, ?string $oldDomain, ?int $userId = null): array
    {
        $jobs = [];

        // 1. Invalidate SSL — the old cert is for the old domain.
        if ($app->ssl_enabled_at || $app->ssl_challenge) {
            $app->forceFill(['ssl_enabled_at' => null, 'ssl_challenge' => null, 'ssl_dns_provider' => null])->save();
        }

        // 2. Remove old vhost file + symlink.
        if ($oldDomain) {
            $oldVhost = escapeshellarg(AppTemplates::vhostPath($oldDomain));
            $oldEnabled = escapeshellarg(AppTemplates::vhostEnabledPath($oldDomain));

            $jobs[] = $this->shell($app, "Remove old vhost for {$oldDomain}", "rm -f {$oldEnabled} {$oldVhost}", $userId);
        }

        // 3. Render new vhost (if domain is not null).
        if ($app->domain) {
            $vars = AppTemplates::vars($app);
            $jobs[] = $this->renderConfig(
                $app,
                "Write nginx vhost for {$app->domain}",
                AppTemplates::vhostPath((string) $app->domain),
                AppTemplates::vhostTemplate($app->app_type),
                $vars,
                $userId,
            );

            // 4. Create symlink + reload.
            $vhostPath = escapeshellarg(AppTemplates::vhostPath((string) $app->domain));
            $enabledPath = escapeshellarg(AppTemplates::vhostEnabledPath((string) $app->domain));

            $jobs[] = $this->shell($app, 'Enable site & reload nginx', <<<SH
                ln -sf {$vhostPath} {$enabledPath}
                nginx -t
                systemctl reload nginx
                SH, $userId);
        } else {
            // No domain — just reload nginx after removing the old vhost.
            $jobs[] = $this->shell($app, 'Reload nginx', "nginx -t && systemctl reload nginx", $userId);
        }

        return $jobs;
    }

    /**
     * Type-specific placeholder document so a freshly created app serves
     * something before the first deploy. WordPress gets none (its core
     * download provides index.php).
     */
    private function placeholder(Application $app): string
    {
        $root = $app->root_path;

        return match ($app->app_type) {
            'static' => <<<SH
                if [ ! -f {$root}/public/index.html ]; then
                    printf '%s\\n' '<h1>It works!</h1>' > {$root}/public/index.html
                fi
                SH,
            'wordpress' => ':',
            default => <<<SH
                if [ ! -f {$root}/public/index.php ]; then
                    printf '%s\\n' '<?php' 'phpinfo();' > {$root}/public/index.php
                fi
                SH,
        };
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
    private function renderConfig(Application $app, string $label, string $path, string $template, array $vars, ?int $userId, string $mode = '0644'): AgentJob
    {
        return $this->dispatcher->dispatch($app->server, 'render_config', [
            'path' => $path,
            'template' => $template,
            'vars' => $vars,
            'mode' => $mode,
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
