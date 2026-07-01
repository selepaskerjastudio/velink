<?php

namespace App\Provisioning;

use App\Models\Application;

/**
 * Per-application PHP-FPM tuning and PHP ini limits.
 *
 * Owns the canonical defaults (matching the values that were previously
 * hardcoded in AppTemplates::PHP_FPM_POOL), merging of DB-stored overrides,
 * validation rules for the settings form, and convenience presets.
 *
 * The merged map uses snake_case keys (e.g. `pm_max_children`) which the
 * PHP_FPM_POOL template interpolates as `{{.pm_max_children}}`.
 */
class PhpSettings
{
    /**
     * The defaults — every key here is also templated in PHP_FPM_POOL, so the
     * two must stay in sync. Existing provisioned apps see identical behavior
     * because these equal the previously hardcoded values.
     *
     * All values are strings: HTTP input round-trips as strings, JSON storage
     * preserves them, and Go text/template prints them verbatim — so keeping
     * the defaults string-typed makes the no-op comparison (===) in the
     * controller type-stable.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            // Process manager.
            'pm' => 'dynamic',
            'pm_max_children' => '5',
            'pm_start_servers' => '2',
            'pm_min_spare_servers' => '1',
            'pm_max_spare_servers' => '3',
            'pm_max_requests' => '0',
            'pm_process_idle_timeout' => '10s',

            // PHP ini limits (php_admin_value — per-pool, not overridable by app).
            'memory_limit' => '128M',
            'max_execution_time' => '30',
            'max_input_time' => '60',
            'upload_max_filesize' => '2M',
            'post_max_size' => '8M',
        ];
    }

    /**
     * Merge an app's stored overrides over the defaults. Keys absent from the
     * stored map fall back to defaults, so a `null` column yields the full
     * default set.
     *
     * @return array<string, string>
     */
    public static function forApp(Application $app): array
    {
        return array_merge(
            self::defaults(),
            array_filter($app->getRawOriginal('php_settings') ? (array) json_decode($app->getRawOriginal('php_settings'), true) : [], fn ($v) => $v !== null),
        );
    }

    /**
     * Laravel validation rules for the settings form. Field names use the
     * snake_case storage keys (they map 1:1 to the request input names).
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'pm' => ['required', 'string', 'in:dynamic,static,ondemand'],
            'pm_max_children' => ['required', 'integer', 'min:1', 'max:1000'],
            'pm_start_servers' => ['required', 'integer', 'min:1', 'max:1000'],
            'pm_min_spare_servers' => ['required', 'integer', 'min:1', 'max:1000'],
            'pm_max_spare_servers' => ['required', 'integer', 'min:1', 'max:1000'],
            'pm_max_requests' => ['required', 'integer', 'min:0', 'max:100000'],
            'pm_process_idle_timeout' => ['required', 'string', 'regex:/^\d+s?$/'],
            'memory_limit' => ['required', 'string', 'regex:/^-1$|^\d+[KMG]?$/'], // "-1" or "128M".
            'max_execution_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'max_input_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'upload_max_filesize' => ['required', 'string', 'regex:/^\d+[KMG]?$/'],
            'post_max_size' => ['required', 'string', 'regex:/^\d+[KMG]?$/'],
        ];
    }

    /**
     * Convenience presets for the settings UI (Low / Medium / High traffic).
     * Only pm.* values differ between presets; ini limits keep defaults.
     *
     * @return array<string, array<string, string>>
     */
    public static function presets(): array
    {
        return [
            'low' => array_merge(self::defaults(), [
                'pm_max_children' => '5',
                'pm_start_servers' => '2',
                'pm_min_spare_servers' => '1',
                'pm_max_spare_servers' => '3',
            ]),
            'medium' => array_merge(self::defaults(), [
                'pm_max_children' => '20',
                'pm_start_servers' => '5',
                'pm_min_spare_servers' => '4',
                'pm_max_spare_servers' => '12',
            ]),
            'high' => array_merge(self::defaults(), [
                'pm_max_children' => '50',
                'pm_start_servers' => '10',
                'pm_min_spare_servers' => '8',
                'pm_max_spare_servers' => '30',
            ]),
        ];
    }
}
