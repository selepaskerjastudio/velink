<?php

namespace App\Provisioning;

use App\Models\Application;

/**
 * Per-application config templates rendered by the agent via the
 * `render_config` action (Go text/template, vars are a flat map so keys
 * here must match the map keys exactly, snake_case).
 *
 * The php-fpm socket path is keyed by linux_user only (not php_version), so
 * switching PHP version never requires touching the nginx vhost — only the
 * pool conf moves between /etc/php/{version}/fpm/pool.d/.
 */
class AppTemplates
{
    public const NGINX_VHOST = <<<'CONF'
        server {
            listen 80;
            listen [::]:80;
            server_name {{.domain}};

            root {{.root_path}}/public;
            index index.php index.html;

            access_log /var/log/nginx/{{.linux_user}}-access.log;
            error_log  /var/log/nginx/{{.linux_user}}-error.log;

            location / {
                try_files $uri $uri/ /index.php?$query_string;
            }

            location ~ \.php$ {
                include snippets/fastcgi-php.conf;
                fastcgi_pass unix:{{.socket_path}};
            }

            location ~ /\.(?!well-known).* {
                deny all;
            }
        }
        CONF;

    public const PHP_FPM_POOL = <<<'CONF'
        [{{.pool_name}}]
        user = {{.linux_user}}
        group = {{.linux_user}}
        listen = {{.socket_path}}
        listen.owner = www-data
        listen.group = www-data
        listen.mode = 0660

        pm = dynamic
        pm.max_children = 5
        pm.start_servers = 2
        pm.min_spare_servers = 1
        pm.max_spare_servers = 3

        chdir = {{.root_path}}
        catch_workers_output = yes

        php_admin_value[open_basedir] = {{.root_path}}:/tmp
        php_admin_value[upload_tmp_dir] = {{.root_path}}/tmp
        php_admin_value[sys_temp_dir] = {{.root_path}}/tmp
        CONF;

    /**
     * @return array<string, string>
     */
    public static function vars(Application $app): array
    {
        return [
            'domain' => (string) $app->domain,
            'root_path' => $app->root_path,
            'linux_user' => $app->linux_user,
            'pool_name' => $app->linux_user,
            'socket_path' => self::socketPath($app->linux_user),
        ];
    }

    public static function socketPath(string $linuxUser): string
    {
        return "/run/php/{$linuxUser}.sock";
    }

    public static function poolConfigPath(string $phpVersion, string $linuxUser): string
    {
        return "/etc/php/{$phpVersion}/fpm/pool.d/{$linuxUser}.conf";
    }

    public static function vhostPath(string $domain): string
    {
        return "/etc/nginx/sites-available/{$domain}.conf";
    }

    public static function vhostEnabledPath(string $domain): string
    {
        return "/etc/nginx/sites-enabled/{$domain}.conf";
    }
}
