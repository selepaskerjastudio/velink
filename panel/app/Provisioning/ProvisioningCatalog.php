<?php

namespace App\Provisioning;

use InvalidArgumentException;

/**
 * Idempotent provisioning recipes for Ubuntu LTS. Each recipe is a list of
 * shell steps the agent executes on the target server. The agent installs all
 * services itself — the panel only describes the work.
 *
 * Commands are written to be safe to re-run (apt installs, repo adds with -y).
 */
class ProvisioningCatalog
{
    public const CORE_COMPONENTS = [
        'base', 'nginx', 'certbot', 'php', 'composer', 'node', 'supervisor', 'redis',
    ];

    public const SECURITY_COMPONENTS = ['ufw', 'fail2ban'];

    public const DB_COMPONENTS = ['mariadb', 'postgresql', 'mongodb'];

    public const COMPONENTS = [
        'base', 'nginx', 'certbot', 'php', 'composer', 'node',
        'supervisor', 'redis', 'mariadb', 'postgresql', 'mongodb',
        'ufw', 'fail2ban',
    ];

    public const PHP_VERSIONS = ['7.4', '8.1', '8.2', '8.3', '8.4'];

    private const NODE_MAJOR = '20';
    private const PG_BASE = 'https://apt.postgresql.org/pub/repos/apt';
    private const MONGO_VERSION = '7.0';

    /**
     * Return the ordered shell steps for a component.
     *
     * @param  array<string, mixed>  $opts
     * @return array<int, array{name: string, type: string, params: array<string, mixed>}>
     */
    public function steps(string $component, array $opts = []): array
    {
        return match ($component) {
            'base' => [$this->shell('Install base packages', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                # Stop auto-update daemons that hold the dpkg lock on fresh VMs.
                systemctl stop unattended-upgrades apt-daily.service apt-daily-upgrade.service 2>/dev/null || true
                systemctl mask unattended-upgrades 2>/dev/null || true
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get update -y
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release software-properties-common apt-transport-https
                SH)],

            'nginx' => [$this->shell('Install nginx', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get update
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y nginx
                systemctl enable --now nginx
                SH)],

            'certbot' => [$this->shell('Install certbot', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y certbot python3-certbot-nginx
                SH)],

            'php' => $this->phpSteps($opts),

            'composer' => [$this->shell('Install composer', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                # The agent runs provisioning steps concurrently, so PHP may still
                # be installing — composer needs the `php` binary, so wait for it.
                for _ in $(seq 1 120); do command -v php >/dev/null 2>&1 && break; sleep 5; done
                command -v php >/dev/null 2>&1 || { echo "php not available after waiting"; exit 1; }
                php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
                php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
                rm -f /tmp/composer-setup.php
                SH)],

            'node' => [$this->shell('Install Node.js', sprintf(<<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                curl -fsSL https://deb.nodesource.com/setup_%s.x | bash -
                # The NodeSource setup script runs apt-get update without the dpkg
                # lock; under concurrent provisioning that can lose to another job
                # and leave Ubuntu's old nodejs as the install candidate. Refresh
                # the package list under the lock so we get the NodeSource version.
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get update
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y nodejs
                SH, self::NODE_MAJOR))],

            'supervisor' => [$this->shell('Install supervisord', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y supervisor
                systemctl enable --now supervisor
                SH)],

            'redis' => [$this->shell('Install Redis', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y redis-server
                systemctl enable --now redis-server
                SH)],

            'ufw' => [$this->shell('Install UFW firewall', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y ufw
                # Default policies: deny incoming, allow outgoing.
                ufw --force reset
                ufw default deny incoming
                ufw default allow outgoing
                # Always allow SSH first so we never lock ourselves out.
                ufw allow 22/tcp
                ufw --force enable
                systemctl enable ufw
                SH)],

            'fail2ban' => [$this->shell('Install Fail2Ban', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y fail2ban
                systemctl enable --now fail2ban
                SH)],

            'mariadb' => [$this->shell('Install MariaDB', <<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y mariadb-server
                systemctl enable --now mariadb
                SH)],

            'postgresql' => [$this->shell('Install PostgreSQL (PGDG)', sprintf(<<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                install -d /usr/share/postgresql-common/pgdg
                curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
                echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] %s $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get update
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y postgresql
                systemctl enable --now postgresql
                SH, self::PG_BASE))],

            'mongodb' => [$this->shell('Install MongoDB', sprintf(<<<'SH'
                export DEBIAN_FRONTEND=noninteractive
                curl -fsSL https://www.mongodb.org/static/pgp/server-%1$s.asc | gpg -o /usr/share/keyrings/mongodb-server-%1$s.gpg --dearmor --yes
                echo "deb [ signed-by=/usr/share/keyrings/mongodb-server-%1$s.gpg ] https://repo.mongodb.org/apt/ubuntu $(lsb_release -cs)/mongodb-org/%1$s multiverse" > /etc/apt/sources.list.d/mongodb-org-%1$s.list
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get update
                flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y mongodb-org
                systemctl enable --now mongod
                SH, self::MONGO_VERSION))],

            default => throw new InvalidArgumentException("unknown component: {$component}"),
        };
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<int, array{name: string, type: string, params: array<string, mixed>}>
     */
    private function phpSteps(array $opts): array
    {
        $versions = $opts['php_versions'] ?? ['8.3'];
        $steps = [$this->shell('Add ondrej/php PPA', <<<'SH'
            export DEBIAN_FRONTEND=noninteractive
            # add-apt-repository ships in software-properties-common (base step),
            # which runs concurrently — wait for it before adding the PPA.
            for _ in $(seq 1 120); do command -v add-apt-repository >/dev/null 2>&1 && break; sleep 5; done
            flock -w 300 /var/lib/dpkg/lock-frontend add-apt-repository -y ppa:ondrej/php
            flock -w 300 /var/lib/dpkg/lock-frontend apt-get update
            SH)];

        foreach ($versions as $v) {
            if (! in_array($v, self::PHP_VERSIONS, true)) {
                throw new InvalidArgumentException("unsupported PHP version: {$v}");
            }
            $pkgs = implode(' ', array_map(
                fn (string $ext) => "php{$v}-{$ext}",
                ['fpm', 'cli', 'common', 'mysql', 'pgsql', 'mbstring', 'xml', 'curl', 'zip', 'gd', 'bcmath', 'intl'],
            ));
            if ($v === '7.4') {
                // PHP 7.4 is only available on Ubuntu 22 and below.
                $steps[] = $this->shell("Install PHP {$v}", sprintf(<<<'SH'
                    UBUNTU_VER=$(lsb_release -rs 2>/dev/null | cut -d. -f1)
                    if [ "${UBUNTU_VER:-99}" -le 22 ]; then
                        export DEBIAN_FRONTEND=noninteractive
                        flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y %s
                        systemctl enable --now php%s-fpm
                    else
                        echo "Skipping PHP 7.4 — not supported on Ubuntu ${UBUNTU_VER}"
                    fi
                    SH, $pkgs, $v));
            } else {
                $steps[] = $this->shell("Install PHP {$v}", sprintf(<<<'SH'
                    export DEBIAN_FRONTEND=noninteractive
                    flock -w 300 /var/lib/dpkg/lock-frontend apt-get install -y %s
                    systemctl enable --now php%s-fpm
                    SH, $pkgs, $v));
            }
        }

        return $steps;
    }

    /**
     * @return array{name: string, type: string, params: array<string, mixed>}
     */
    private function shell(string $name, string $command): array
    {
        // Normalise heredoc indentation into a clean, sh-safe script.
        $lines = array_map('trim', explode("\n", trim($command)));

        return [
            'name' => $name,
            'type' => 'shell',
            'params' => [
                'command' => "set -e\necho \"==> {$name}\"\n".implode("\n", $lines),
                'timeout' => 1800,
            ],
        ];
    }
}
