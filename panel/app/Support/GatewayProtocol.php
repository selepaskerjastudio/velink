<?php

namespace App\Support;

/**
 * Wire constants shared with the Go gateway/agent. Keep in sync with
 * gateway/internal/protocol/protocol.go.
 */
final class GatewayProtocol
{
    /** Redis connection (config/database.php) with an empty prefix. */
    public const REDIS_CONNECTION = 'gateway';

    public const CHANNEL_DISPATCH = 'velink:gateway:dispatch';
    public const CHANNEL_INBOUND = 'velink:gateway:inbound';
    public const CHANNEL_PRESENCE = 'velink:gateway:presence';

    public const TYPE_HELLO = 'hello';
    public const TYPE_HEARTBEAT = 'heartbeat';
    public const TYPE_JOB = 'job';
    public const TYPE_JOB_OUTPUT = 'job_output';
    public const TYPE_JOB_RESULT = 'job_result';
    public const TYPE_ERROR = 'error';
    public const TYPE_METRICS = 'metrics';
    public const TYPE_SYSINFO = 'sysinfo';

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';

    public static function presenceKey(int $serverId): string
    {
        return "velink:presence:server:{$serverId}";
    }
}
