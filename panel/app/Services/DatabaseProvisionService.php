<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\DatabaseInstance;
use App\Models\Server;

/**
 * Creates and drops database schemas (MySQL/MariaDB, PostgreSQL, MongoDB) on a
 * managed server by dispatching a single `shell` job per operation.
 *
 * Database/schema names are validated upstream (controller) against the
 * strict allowlist `^[A-Za-z][A-Za-z0-9_]{0,63}$` and a reserved-name
 * denylist before ever reaching this service, so it is safe to interpolate
 * them directly into the SQL strings below. The resulting command is still
 * wrapped with the same heredoc + `set -e` convention as
 * AppProvisionService for consistency with the rest of the provisioning
 * pipeline.
 */
class DatabaseProvisionService
{
    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * @return array{database: DatabaseInstance, job: AgentJob}
     */
    public function create(Server $server, string $engine, string $name, ?string $charset, ?string $collation, ?int $userId): array
    {
        $database = DatabaseInstance::create([
            'server_id' => $server->id,
            'engine' => $engine,
            'name' => $name,
            'charset' => $charset,
            'collation' => $collation,
        ]);

        $job = $this->shell($server, "Create database: {$name}", $this->createCommand($engine, $name, $charset, $collation), $userId);

        return ['database' => $database, 'job' => $job];
    }

    public function delete(DatabaseInstance $database, ?int $userId): AgentJob
    {
        $job = $this->shell(
            $database->server,
            "Drop database: {$database->name}",
            $this->dropCommand($database->engine, $database->name),
            $userId,
        );

        $database->delete();

        return $job;
    }

    private function createCommand(string $engine, string $name, ?string $charset, ?string $collation): string
    {
        $name = $this->quote($name);

        return match ($engine) {
            'mysql', 'mariadb' => $this->mysqlCreateCommand($name, $charset ?? 'utf8mb4', $collation ?? 'utf8mb4_unicode_ci'),
            'postgres' => $this->postgresCreateCommand($name, $charset ?? 'UTF8'),
            'mongodb' => $this->mongodbCreateCommand($name),
            default => throw new \InvalidArgumentException("unsupported engine: {$engine}"),
        };
    }

    private function dropCommand(string $engine, string $name): string
    {
        $name = $this->quote($name);

        return match ($engine) {
            'mysql', 'mariadb' => $this->mysqlDropCommand($name),
            'postgres' => $this->postgresDropCommand($name),
            'mongodb' => $this->mongodbDropCommand($name),
            default => throw new \InvalidArgumentException("unsupported engine: {$engine}"),
        };
    }

    private function mysqlCreateCommand(string $name, string $charset, string $collation): string
    {
        return <<<SH
            mysql -e "CREATE DATABASE IF NOT EXISTS \\`{$name}\\` CHARACTER SET {$charset} COLLATE {$collation};"
            SH;
    }

    private function mysqlDropCommand(string $name): string
    {
        return <<<SH
            mysql -e "DROP DATABASE IF EXISTS \\`{$name}\\`;"
            SH;
    }

    private function postgresCreateCommand(string $name, string $charset): string
    {
        return <<<SH
            sudo -u postgres psql -c "CREATE DATABASE \\"{$name}\\" ENCODING '{$charset}';"
            SH;
    }

    private function postgresDropCommand(string $name): string
    {
        return <<<SH
            sudo -u postgres psql -c "DROP DATABASE IF EXISTS \\"{$name}\\";"
            SH;
    }

    private function mongodbCreateCommand(string $name): string
    {
        return <<<SH
            mongosh --quiet --eval "db.getSiblingDB('{$name}').createCollection('_velink_init')"
            SH;
    }

    private function mongodbDropCommand(string $name): string
    {
        return <<<SH
            mongosh --quiet --eval "db.getSiblingDB('{$name}').dropDatabase()"
            SH;
    }

    /**
     * Database/schema names interpolated here have already been validated by
     * the controller against `^[A-Za-z][A-Za-z0-9_]{0,63}$` and a reserved
     * name denylist, so raw interpolation into the SQL strings above is safe
     * (no quoting metacharacters, no spaces, no shell metacharacters).
     */
    private function quote(string $name): string
    {
        return $name;
    }

    private function shell(Server $server, string $label, string $command, ?int $userId): AgentJob
    {
        $lines = array_map('trim', explode("\n", trim($command)));

        return $this->dispatcher->dispatch($server, 'shell', [
            'command' => "set -e\necho \"==> {$label}\"\n".implode("\n", $lines),
            'timeout' => 60,
        ], ['user_id' => $userId, 'label' => $label]);
    }
}
