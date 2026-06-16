<?php

namespace App\Support;

class DatabaseNaming
{
    public const DB_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    public const CHARSET_REGEX = '/^[A-Za-z0-9_]+$/';

    public const USERNAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,31}$/';

    public const HOST_REGEX = '/^(%|[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,62})?)$/';

    /**
     * Safe password charset — excludes quotes, backslash, $ and backtick so the
     * value can be interpolated into the single-quoted SQL/double-quoted shell
     * commands in DatabaseUserProvisionService without escaping.
     */
    public const PASSWORD_REGEX = '/^[A-Za-z0-9!@#%^*()_+=.\-]{8,64}$/';

    /**
     * Reserved/system database names, checked case-insensitively, across all
     * supported engines (MySQL/MariaDB, PostgreSQL, MongoDB).
     */
    public const RESERVED_NAMES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
        'postgres',
        'template0',
        'template1',
        'admin',
        'local',
        'config',
    ];

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_NAMES, true);
    }
}
