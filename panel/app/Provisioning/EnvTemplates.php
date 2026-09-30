<?php

namespace App\Provisioning;

use App\Models\Application;

/**
 * Default .env content seeded onto an application at creation time.
 *
 * The repository (and its .env.example) only arrives with the first deploy,
 * so these panel-side templates stand in for it — mirroring Laravel's stock
 * .env.example for Laravel apps, a lean generic set for custom PHP ones.
 * The DB block always carries the credentials provisioned with the app,
 * never framework placeholder values.
 */
class EnvTemplates
{
    /**
     * @param  array{name: string, user: string, password: string, host?: string}|null  $dbCreds
     */
    public static function forApplication(Application $app, ?array $dbCreds): ?string
    {
        // WordPress wires its own wp-config.php; static apps have no PHP.
        if ($app->app_type === 'wordpress' || ! $app->usesPhp()) {
            return null;
        }

        return $app->app_type === 'laravel'
            ? self::laravel($app, $dbCreds)
            : self::generic($app, $dbCreds);
    }

    /**
     * Laravel's .env.example, pre-filled for a production web app. Drivers
     * follow the example's database-backed set only when a database was
     * actually provisioned — otherwise they fall back to no-DB defaults so
     * a fresh app boots without a missing table.
     *
     * @param  array{name: string, user: string, password: string, host?: string}|null  $dbCreds
     */
    private static function laravel(Application $app, ?array $dbCreds): string
    {
        $dev = $app->stack_mode === 'development';

        $lines = [
            "APP_NAME=\"{$app->name}\"",
            'APP_ENV='.($dev ? 'local' : 'production'),
            'APP_KEY='.'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG='.($dev ? 'true' : 'false'),
            "APP_URL=https://{$app->domain}",
            'APP_TIMEZONE=UTC',
            'APP_LOCALE=en',
            '',
            'LOG_CHANNEL=stack',
            'LOG_STACK=single',
            'LOG_DEPRECATIONS_CHANNEL=null',
            'LOG_LEVEL='.($dev ? 'debug' : 'error'),
            '',
            ...($dbCreds !== null ? self::dbBlock($dbCreds) : []),
            'SESSION_DRIVER='.($dbCreds !== null ? 'database' : 'file'),
            'SESSION_LIFETIME=120',
            '',
            'BROADCAST_CONNECTION=log',
            'FILESYSTEM_DISK=local',
            'QUEUE_CONNECTION='.($dbCreds !== null ? 'database' : 'sync'),
            'CACHE_STORE='.($dbCreds !== null ? 'database' : 'file'),
            '',
            'MAIL_MAILER=log',
            'MAIL_FROM_ADDRESS="hello@example.com"',
            'MAIL_FROM_NAME="${APP_NAME}"',
            '',
            'VITE_APP_NAME="${APP_NAME}"',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Lean generic set for custom PHP apps — no framework-specific keys.
     *
     * @param  array{name: string, user: string, password: string, host?: string}|null  $dbCreds
     */
    private static function generic(Application $app, ?array $dbCreds): string
    {
        $dev = $app->stack_mode === 'development';

        $lines = [
            "APP_NAME=\"{$app->name}\"",
            'APP_ENV='.($dev ? 'local' : 'production'),
            'APP_DEBUG='.($dev ? 'true' : 'false'),
            "APP_URL=https://{$app->domain}",
            '',
            ...($dbCreds !== null ? self::dbBlock($dbCreds) : []),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * DB block wired to the freshly-provisioned database and user. MariaDB
     * grants are created for @'localhost', so DB_HOST must be `localhost` —
     * PDO then connects over the unix socket and matches that grant;
     * `127.0.0.1` would authenticate over TCP and be denied. Postgres roles
     * authenticate over TCP instead.
     *
     * @param  array{name: string, user: string, password: string, host?: string, engine?: string}  $dbCreds
     * @return list<string>
     */
    private static function dbBlock(array $dbCreds): array
    {
        [$connection, $host, $port] = match ($dbCreds['engine'] ?? 'mariadb') {
            'postgres' => ['pgsql', '127.0.0.1', '5432'],
            default => ['mysql', 'localhost', '3306'],
        };

        return [
            "DB_CONNECTION={$connection}",
            "DB_HOST={$host}",
            "DB_PORT={$port}",
            "DB_DATABASE={$dbCreds['name']}",
            "DB_USERNAME={$dbCreds['user']}",
            "DB_PASSWORD={$dbCreds['password']}",
            '',
        ];
    }
}
