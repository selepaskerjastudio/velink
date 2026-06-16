<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ProvisioningCatalog;
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
        'nginx' => ['unit' => 'nginx',        'label' => 'NGINX'],
        'supervisor' => ['unit' => 'supervisor',   'label' => 'Supervisord'],
        'redis' => ['unit' => 'redis-server', 'label' => 'Redis'],
        'mariadb' => ['unit' => 'mariadb',      'label' => 'MariaDB'],
        'postgresql' => ['unit' => 'postgresql',   'label' => 'PostgreSQL'],
        'mongodb' => ['unit' => 'mongod',       'label' => 'MongoDB'],
    ];

    /** Job label used to identify auto-probe shell jobs so results can be parsed. */
    public const PROBE_LABEL = 'velink:service-probe';

    /**
     * Service lifecycle statuses surfaced in the UI. Provisioned units move
     * waiting → installing → running (or not_installed on failure); control
     * actions move between running/stopped/restarting.
     */
    public const STATUS_WAITING = 'waiting';        // queued for install

    public const STATUS_INSTALLING = 'installing';  // install job is running

    public const STATUS_RUNNING = 'running';        // installed and active

    public const STATUS_STOPPED = 'stopped';        // installed but inactive

    public const STATUS_RESTARTING = 'restarting';  // restart in progress

    public const STATUS_NOT_INSTALLED = 'not_installed'; // install failed / absent

    public function __construct(private JobDispatcher $dispatcher) {}

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
            'start', 'reload' => $service->status = self::STATUS_RUNNING,
            'restart' => $service->status = self::STATUS_RESTARTING,
            'stop' => $service->status = self::STATUS_STOPPED,
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
     * @param  array<int, string>  $phpVersions  e.g. ['8.3', '8.4']
     */
    public function seedForServer(Server $server, array $components, array $phpVersions = []): void
    {
        foreach ($components as $component) {
            if (isset(self::WELL_KNOWN_SERVICES[$component])) {
                $def = self::WELL_KNOWN_SERVICES[$component];
                Service::firstOrCreate(
                    ['server_id' => $server->id, 'name' => $def['unit'], 'type' => 'systemd'],
                    ['status' => self::STATUS_WAITING, 'config' => ['label' => $def['label']], 'application_id' => null],
                );
            }
        }

        if (in_array('php', $components, true)) {
            foreach ($phpVersions as $version) {
                Service::firstOrCreate(
                    ['server_id' => $server->id, 'name' => "php{$version}-fpm", 'type' => 'systemd'],
                    ['status' => self::STATUS_WAITING, 'config' => ['label' => "PHP {$version} FPM"], 'application_id' => null],
                );
            }
        }
    }

    /**
     * Build the shell command that probes which well-known systemd units are
     * installed on the server. Each installed unit is printed as "name=status"
     * (one per line). Units that do not exist are silently skipped.
     */
    public function probeCommand(): string
    {
        $staticUnits = array_column(self::WELL_KNOWN_SERVICES, 'unit');
        $phpUnits = array_map(fn ($v) => "php{$v}-fpm", ProvisioningCatalog::PHP_VERSIONS);
        $all = array_merge($staticUnits, $phpUnits);
        $list = implode(' ', array_map('escapeshellarg', $all));

        return "for svc in {$list}; do\n"
            ."  if systemctl cat \"\$svc\" >/dev/null 2>&1; then\n"
            ."    echo \"\$svc=\$(systemctl is-active \"\$svc\" 2>/dev/null || true)\"\n"
            ."  fi\n"
            .'done';
    }

    /**
     * Parse the output of a probe job and create Service rows for every unit
     * that the agent reported. Uses firstOrCreate so repeated probes are safe.
     */
    public function seedFromProbeOutput(Server $server, string $output): void
    {
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (! preg_match('/^([a-zA-Z0-9._@:-]+)=(active|inactive|failed|activating|deactivating|unknown)$/', $line, $m)) {
                continue;
            }

            [, $unit, $rawStatus] = $m;

            Service::firstOrCreate(
                ['server_id' => $server->id, 'name' => $unit, 'type' => 'systemd'],
                ['status' => self::mapSystemctlStatus($rawStatus), 'config' => ['label' => $this->labelForUnit($unit)], 'application_id' => null],
            );
        }
    }

    /** Map a raw `systemctl is-active` value to a UI lifecycle status. */
    public static function mapSystemctlStatus(string $raw): string
    {
        return match ($raw) {
            'active' => self::STATUS_RUNNING,
            'activating' => self::STATUS_RESTARTING,
            'inactive', 'deactivating' => self::STATUS_STOPPED,
            'failed' => self::STATUS_NOT_INSTALLED,
            default => self::STATUS_STOPPED,
        };
    }

    /**
     * The systemd unit(s) a provisioning step installs, derived from its job
     * label (e.g. "Install nginx" → nginx, "Install PHP 8.3" → php8.3-fpm).
     * Steps that don't produce a tracked service (base, PPA, certbot, composer,
     * node) return an empty array.
     *
     * @return array<int, string>
     */
    public function unitsForJobLabel(string $label): array
    {
        if (preg_match('/PHP (\d+\.\d+)/', $label, $m)) {
            return ["php{$m[1]}-fpm"];
        }

        foreach (['nginx' => 'nginx', 'Redis' => 'redis-server', 'supervisor' => 'supervisor', 'MariaDB' => 'mariadb', 'PostgreSQL' => 'postgresql', 'MongoDB' => 'mongod'] as $needle => $unit) {
            if (str_contains($label, $needle)) {
                return [$unit];
            }
        }

        return [];
    }

    /**
     * Update the lifecycle status of the given systemd units on a server.
     *
     * @param  array<int, string>  $units
     */
    public function setUnitsStatus(Server $server, array $units, string $status): void
    {
        if ($units === []) {
            return;
        }

        Service::where('server_id', $server->id)
            ->where('type', 'systemd')
            ->whereIn('name', $units)
            ->update(['status' => $status]);
    }

    /**
     * Reconcile a service's status from a completed control job whose label is
     * "{Action} {unit}" (e.g. "Restart nginx"). Returns true if it handled the
     * label. Used to resolve the optimistic `restarting` state once the restart
     * actually finishes.
     */
    public function applyControlJobResult(Server $server, string $label, bool $succeeded): bool
    {
        if (! preg_match('/^(Start|Restart|Reload|Stop) (.+)$/', $label, $m)) {
            return false;
        }

        if ($succeeded) {
            $status = $m[1] === 'Stop' ? self::STATUS_STOPPED : self::STATUS_RUNNING;
            $this->setUnitsStatus($server, [$m[2]], $status);
        }

        return true;
    }

    private function labelForUnit(string $unit): string
    {
        foreach (self::WELL_KNOWN_SERVICES as $def) {
            if ($def['unit'] === $unit) {
                return $def['label'];
            }
        }

        if (preg_match('/^php(\d+\.\d+)-fpm$/', $unit, $m)) {
            return "PHP {$m[1]} FPM";
        }

        return $unit;
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
