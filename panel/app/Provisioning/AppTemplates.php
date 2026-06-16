<?php

namespace App\Provisioning;

use App\Models\Application;
use Illuminate\Support\Str;

/**
 * Per-application config templates rendered by the agent via the
 * `render_config` action (Go text/template, vars are a flat map so keys
 * here must match the map keys exactly, snake_case).
 *
 * Per-app identity (folder, php-fpm pool name, socket, pool conf filename,
 * nginx log filenames) is keyed by `app_slug`, NOT by the OS user — every app
 * shares the one `velink` Linux user. The socket path is keyed by app_slug
 * (not php_version), so switching PHP version never requires touching the
 * nginx vhost — only the pool conf moves between /etc/php/{version}/fpm/pool.d/.
 */
class AppTemplates
{
    /** PHP application (custom / laravel): nginx + php-fpm. */
    public const NGINX_VHOST = <<<'CONF'
        server {
            listen 80;
            listen [::]:80;
            server_name {{.domain}};

            root {{.web_root}};
            index index.php index.html;

            access_log {{.access_log}};
            error_log  {{.error_log}};

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

    /** Static site: nginx serves files straight from disk, no php-fpm. */
    public const NGINX_VHOST_STATIC = <<<'CONF'
        server {
            listen 80;
            listen [::]:80;
            server_name {{.domain}};

            root {{.web_root}};
            index index.html index.htm;

            access_log {{.access_log}};
            error_log  {{.error_log}};

            location / {
                try_files $uri $uri/ =404;
            }

            location ~ /\.(?!well-known).* {
                deny all;
            }
        }
        CONF;

    /** WordPress: php-fpm + permalink-friendly try_files. */
    public const NGINX_VHOST_WORDPRESS = <<<'CONF'
        server {
            listen 80;
            listen [::]:80;
            server_name {{.domain}};

            root {{.web_root}};
            index index.php index.html;

            access_log {{.access_log}};
            error_log  {{.error_log}};

            location / {
                try_files $uri $uri/ /index.php?$args;
            }

            location ~ \.php$ {
                include snippets/fastcgi-php.conf;
                fastcgi_pass unix:{{.socket_path}};
            }

            location = /xmlrpc.php {
                deny all;
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
        php_admin_flag[display_errors] = {{.display_errors}}
        php_admin_value[opcache.validate_timestamps] = {{.opcache_validate_timestamps}}
        CONF;

    /**
     * Minimal wp-config.php. DB credentials and salts are supplied as vars by
     * AppProvisionService. WordPress only supports MySQL/MariaDB, so DB_HOST is
     * always localhost here. The WordPress install wizard (browser) creates the
     * admin account on first visit.
     */
    public const WORDPRESS_WP_CONFIG = <<<'CONF'
        <?php
        define( 'DB_NAME', '{{.db_name}}' );
        define( 'DB_USER', '{{.db_user}}' );
        define( 'DB_PASSWORD', '{{.db_password}}' );
        define( 'DB_HOST', '{{.db_host}}' );
        define( 'DB_CHARSET', 'utf8mb4' );
        define( 'DB_COLLATE', '' );

        define( 'AUTH_KEY',         '{{.auth_key}}' );
        define( 'SECURE_AUTH_KEY',  '{{.secure_auth_key}}' );
        define( 'LOGGED_IN_KEY',    '{{.logged_in_key}}' );
        define( 'NONCE_KEY',        '{{.nonce_key}}' );
        define( 'AUTH_SALT',        '{{.auth_salt}}' );
        define( 'SECURE_AUTH_SALT', '{{.secure_auth_salt}}' );
        define( 'LOGGED_IN_SALT',   '{{.logged_in_salt}}' );
        define( 'NONCE_SALT',       '{{.nonce_salt}}' );

        $table_prefix = 'wp_';

        define( 'WP_DEBUG', {{.wp_debug}} );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ . '/' );
        }

        require_once ABSPATH . 'wp-settings.php';
        CONF;

    /**
     * Flat variable map for the nginx vhost and php-fpm pool templates.
     *
     * @return array<string, string>
     */
    public static function vars(Application $app): array
    {
        $slug = self::slug($app);

        return [
            'domain' => (string) $app->domain,
            'root_path' => $app->root_path,
            'web_root' => self::webRoot($app),
            'linux_user' => $app->linux_user,
            'app_slug' => $slug,
            'pool_name' => $slug,
            'socket_path' => self::socketPath($slug),
            'access_log' => self::logPath($slug, 'access'),
            'error_log' => self::logPath($slug, 'error'),
            'display_errors' => $app->stack_mode === 'development' ? 'On' : 'Off',
            'opcache_validate_timestamps' => $app->stack_mode === 'development' ? '1' : '0',
        ];
    }

    /**
     * wp-config.php variables. Salts are freshly generated per app; the
     * password/salts are alphanumeric so they interpolate safely into the
     * single-quoted PHP literals above.
     *
     * @param  array{name: string, user: string, password: string, host?: string}  $db
     * @return array<string, string>
     */
    public static function wordpressVars(Application $app, array $db): array
    {
        return [
            'db_name' => $db['name'],
            'db_user' => $db['user'],
            'db_password' => $db['password'],
            'db_host' => $db['host'] ?? 'localhost',
            'auth_key' => Str::random(64),
            'secure_auth_key' => Str::random(64),
            'logged_in_key' => Str::random(64),
            'nonce_key' => Str::random(64),
            'auth_salt' => Str::random(64),
            'secure_auth_salt' => Str::random(64),
            'logged_in_salt' => Str::random(64),
            'nonce_salt' => Str::random(64),
            'wp_debug' => $app->stack_mode === 'development' ? 'true' : 'false',
        ];
    }

    /** Select the nginx vhost template for an app type. */
    public static function vhostTemplate(string $appType): string
    {
        return match ($appType) {
            'static' => self::NGINX_VHOST_STATIC,
            'wordpress' => self::NGINX_VHOST_WORDPRESS,
            default => self::NGINX_VHOST,
        };
    }

    /**
     * The directory nginx serves from. Laravel/custom apps live behind a
     * /public front controller; WordPress and static sites serve from root.
     */
    public static function webRoot(Application $app): string
    {
        return match ($app->app_type) {
            'wordpress', 'static' => $app->root_path,
            default => $app->root_path.'/public',
        };
    }

    /** Per-app identity slug, falling back to linux_user for legacy rows. */
    public static function slug(Application $app): string
    {
        return $app->app_slug ?: $app->linux_user;
    }

    public static function socketPath(string $appSlug): string
    {
        return "/run/php/{$appSlug}.sock";
    }

    public static function poolConfigPath(string $phpVersion, string $appSlug): string
    {
        return "/etc/php/{$phpVersion}/fpm/pool.d/{$appSlug}.conf";
    }

    public static function logPath(string $appSlug, string $kind): string
    {
        return self::logsDir()."/{$appSlug}_{$kind}.log";
    }

    public static function logsDir(): string
    {
        return self::homeDir().'/logs';
    }

    public static function webappsDir(): string
    {
        return self::homeDir().'/webapps';
    }

    public static function homeDir(): string
    {
        return '/home/'.self::webappUser();
    }

    public static function webappUser(): string
    {
        return (string) config('velink.webapp_user', 'velink');
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
