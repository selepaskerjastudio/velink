<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $action,
        ?string $description = null,
        ?int $userId = null,
        ?int $serverId = null,
        array $properties = [],
    ): void {
        AuditLog::create([
            'action' => $action,
            'description' => $description,
            'user_id' => $userId,
            'server_id' => $serverId,
            'properties' => $properties ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
