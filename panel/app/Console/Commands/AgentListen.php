<?php

namespace App\Console\Commands;

use App\Services\GatewayInboundProcessor;
use App\Support\GatewayProtocol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Long-running consumer of the gateway bus. Subscribes to the inbound and
 * presence channels and delegates each message to GatewayInboundProcessor,
 * which updates the database and broadcasts to the browser.
 *
 * Run under a supervisor alongside Horizon/Reverb.
 */
class AgentListen extends Command
{
    protected $signature = 'agent:listen';

    protected $description = 'Consume agent inbound + presence messages from the gateway bus';

    public function handle(GatewayInboundProcessor $processor): int
    {
        $this->info('Listening on gateway bus (inbound + presence)...');

        Redis::connection(GatewayProtocol::REDIS_CONNECTION)->subscribe(
            [GatewayProtocol::CHANNEL_INBOUND, GatewayProtocol::CHANNEL_PRESENCE],
            function (string $message, string $channel) use ($processor): void {
                try {
                    match ($channel) {
                        GatewayProtocol::CHANNEL_INBOUND => $processor->handleInbound($message),
                        GatewayProtocol::CHANNEL_PRESENCE => $processor->handlePresence($message),
                        default => null,
                    };
                } catch (\Throwable $e) {
                    report($e);
                    $this->error($e->getMessage());
                }
            }
        );

        return self::SUCCESS;
    }
}
