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

    public const CHANNEL_DISPATCH = 'coruncloud:gateway:dispatch';
    public const CHANNEL_INBOUND = 'coruncloud:gateway:inbound';
    public const CHANNEL_PRESENCE = 'coruncloud:gateway:presence';

    public const TYPE_HELLO = 'hello';
    public const TYPE_HEARTBEAT = 'heartbeat';
    public const TYPE_JOB = 'job';
    public const TYPE_JOB_OUTPUT = 'job_output';
    public const TYPE_JOB_RESULT = 'job_result';
    public const TYPE_ERROR = 'error';

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';

    public static function presenceKey(int $serverId): string
    {
        return "coruncloud:presence:server:{$serverId}";
    }
}
