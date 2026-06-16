<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use InvalidArgumentException;

/**
 * Registers and controls arbitrary systemd services on a managed server
 * (e.g. nginx, mysql, redis-server, php8.3-fpm). Server-wide services only —
 * per-application workers are handled by a different slice.
 */
class ServiceManager
{
    /**
     * Systemd unit names: letters, digits, and the characters - _ . @ : ,
     * 1-255 characters. See systemd.unit(5) "Unit name format". Anything
     * else is rejected before it can reach a shell command.
     */
    public const UNIT_NAME_REGEX = '/^[a-zA-Z0-9\-_.@:]{1,255}$/';

    /**
     * Maps provisioning component names to their systemd unit name and display label.
     * PHP FPM units are handled separately (one per version).
     */
    public const WELL_KNOWN_SERVICES = [
        'nginx'      => ['unit' => 'nginx',        'label' => 'NGINX'],
        'supervisor' => ['unit' => 'supervisor',   'label' => 'Supervisord'],
        'redis'      => ['unit' => 'redis-server', 'label' => 'Redis'],
        'mariadb'    => ['unit' => 'mariadb',      'label' => 'MariaDB'],
        'postgresql' => ['unit' => 'postgresql',   'label' => 'PostgreSQL'],
        'mongodb'    => ['unit' => 'mongod',       'label' => 'MongoDB'],
    ];

    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * Start tracking a systemd unit on this server. Does not install or
     * start anything on the server itself.
     */
    public function register(Server $server, string $name, ?string $label = null): Service
    {
        if (preg_match(self::UNIT_NAME_REGEX, $name) !== 1) {
            throw new InvalidArgumentException("Invalid systemd unit name: {$name}");
        }

        return Service::create([
            'server_id' => $server->id,
            'application_id' => null,
            'type' => 'systemd',
            'name' => $name,
            'status' => 'unknown',
            'config' => $label !== null ? ['label' => $label] : null,
        ]);
    }

    /**
     * Run a systemctl action against the service's unit and optimistically
     * update the local status/config to reflect the expected outcome.
     *
     * @param  string  $action  One of start|stop|restart|reload|enable|disable.
     */
    public function control(Service $service, string $action): AgentJob
    {
        $name = escapeshellarg($service->name);
        $label = ucfirst($action).' '.$service->name;

        $job = $this->dispatcher->dispatch($service->server, 'shell', [
            'command' => "set -e\necho \"==> {$label}\"\nsudo systemctl {$action} {$name}",
            'timeout' => 60,
        ], ['user_id' => auth()->id(), 'label' => $label]);

        match ($action) {
            'start', 'restart', 'reload' => $service->status = 'active',
            'stop' => $service->status = 'inactive',
            'enable' => $service->config = [...($service->config ?? []), 'enabled' => true],
            'disable' => $service->config = [...($service->config ?? []), 'enabled' => false],
            default => null,
        };

        $service->save();

        return $job;
    }

    /**
     * Dispatch a job to check the live status/enablement of the unit.
     *
     * Note: this does NOT update $service->status synchronously — there is
     * no job-completion callback wired up yet to parse the output and
     * persist it. The user reads the result from the live job output.
     */
    public function refreshStatus(Service $service): AgentJob
    {
        $name = escapeshellarg($service->name);

        return $this->dispatcher->dispatch($service->server, 'shell', [
            'command' => "systemctl is-active {$name}; systemctl is-enabled {$name}",
            'timeout' => 30,
        ], ['user_id' => auth()->id(), 'label' => "Check status: {$service->name}"]);
    }

    /**
     * Auto-register well-known systemd services for a server based on the
     * provisioned components. Uses firstOrCreate so it is safe to call
     * multiple times (idempotent).
     *
     * @param  array<int, string>  $components  e.g. ['nginx', 'php', 'redis']
     * @param  array<int, string>  $phpVersions e.g. ['8.3', '8.4']
     */
    public function seedForServer(Server $server, array $components, array $phpVersions = []): void
    {
        foreach ($components as $component) {
            if (isset(self::WELL_KNOWN_SERVICES[$component])) {
                $def = self::WELL_KNOWN_SERVICES[$component];
                Service::firstOrCreate(
                    ['server_id' => $server->id, 'name' => $def['unit'], 'type' => 'systemd'],
                    ['status' => 'unknown', 'config' => ['label' => $def['label']], 'application_id' => null],
                );
            }
        }

        if (in_array('php', $components, true)) {
            foreach ($phpVersions as $version) {
                Service::firstOrCreate(
                    ['server_id' => $server->id, 'name' => "php{$version}-fpm", 'type' => 'systemd'],
                    ['status' => 'unknown', 'config' => ['label' => "PHP {$version} FPM"], 'application_id' => null],
                );
            }
        }
    }

    /**
     * Stop tracking this service in the panel. This only deletes the
     * `Service` row — it does NOT stop, disable, or uninstall the actual
     * systemd unit on the server.
     */
    public function unregister(Service $service): void
    {
        $service->delete();
    }
}
