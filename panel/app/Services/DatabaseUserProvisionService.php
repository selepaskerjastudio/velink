<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\DatabaseUser;
use App\Models\Server;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, re-grants, and drops database users (MySQL/MariaDB, PostgreSQL,
 * MongoDB) on a managed server by dispatching `shell` jobs.
 *
 * Usernames/hosts are validated upstream (controller) against
 * DatabaseUserController::USERNAME_REGEX / HOST_REGEX, and grant keys are
 * validated against the same database-name allowlist used by
 * DatabaseProvisionService, so it is safe to interpolate them directly into
 * the SQL strings below. Passwords are generated server-side via
 * Str::password(24, symbols: false) — alphanumeric only, so they never need
 * escaping inside the single/double-quoted SQL literals here.
 */
class DatabaseUserProvisionService
{
    /**
     * Abstract privilege names accepted in `grants`, per engine. The current
     * UI only ever sends ['ALL'], but the allowlist exists so the API can be
     * extended without loosening validation.
     *
     * @var array<string, array<int, string>>
     */
    public const PRIVILEGES = [
        'mysql' => ['ALL', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'EXECUTE'],
        'mariadb' => ['ALL', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'EXECUTE'],
        'postgres' => ['ALL', 'CONNECT', 'CREATE', 'TEMP'],
        'mongodb' => ['ALL', 'readWrite', 'read', 'dbAdmin', 'dbOwner'],
    ];

    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     * @return array{databaseUser: DatabaseUser, job: AgentJob, plainPassword: string}
     */
    public function create(Server $server, string $engine, string $username, string $host, array $grants, ?int $userId): array
    {
        $password = Str::password(24, symbols: false);

        $databaseUser = DatabaseUser::create([
            'server_id' => $server->id,
            'engine' => $engine,
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'grants' => $grants,
        ]);

        $job = $this->shell(
            $server,
            "Create database user: {$username}",
            $this->createCommand($engine, $username, $host, $password, $grants),
            $userId,
        );

        return ['databaseUser' => $databaseUser, 'job' => $job, 'plainPassword' => $password];
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    public function updateGrants(DatabaseUser $databaseUser, array $grants, ?int $userId): AgentJob
    {
        $oldGrants = $databaseUser->grants ?? [];

        $job = $this->shell(
            $databaseUser->server,
            "Update grants: {$databaseUser->username}",
            $this->grantsCommand($databaseUser->engine, $databaseUser->username, $databaseUser->host, $oldGrants, $grants),
            $userId,
        );

        $databaseUser->forceFill(['grants' => $grants])->save();

        return $job;
    }

    public function delete(DatabaseUser $databaseUser, ?int $userId): AgentJob
    {
        $job = $this->shell(
            $databaseUser->server,
            "Drop database user: {$databaseUser->username}",
            $this->dropCommand($databaseUser->engine, $databaseUser->username, $databaseUser->host, $databaseUser->grants ?? []),
            $userId,
        );

        $databaseUser->delete();

        return $job;
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function createCommand(string $engine, string $username, string $host, string $password, array $grants): string
    {
        return match ($engine) {
            'mysql', 'mariadb' => $this->mysqlCreateCommand($username, $host, $password, $grants),
            'postgres' => $this->postgresCreateCommand($username, $password, $grants),
            'mongodb' => $this->mongodbCreateCommand($username, $password, $grants),
            default => throw new InvalidArgumentException("unsupported engine: {$engine}"),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $oldGrants
     * @param  array<string, array<int, string>>  $newGrants
     */
    private function grantsCommand(string $engine, string $username, string $host, array $oldGrants, array $newGrants): string
    {
        return match ($engine) {
            'mysql', 'mariadb' => $this->mysqlGrantsCommand($username, $host, $newGrants, revokeAllFirst: true),
            'postgres' => $this->postgresGrantsCommand($username, $oldGrants, $newGrants),
            'mongodb' => $this->mongodbUpdateRolesCommand($username, $newGrants),
            default => throw new InvalidArgumentException("unsupported engine: {$engine}"),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function dropCommand(string $engine, string $username, string $host, array $grants): string
    {
        return match ($engine) {
            'mysql', 'mariadb' => <<<SH
                mysql -e "DROP USER IF EXISTS '{$username}'@'{$host}';"
                SH,
            'postgres' => $this->postgresDropCommand($username, $grants),
            'mongodb' => <<<SH
                mongosh --quiet --eval "db.getSiblingDB('admin').dropUser('{$username}')"
                SH,
            default => throw new InvalidArgumentException("unsupported engine: {$engine}"),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function mysqlCreateCommand(string $username, string $host, string $password, array $grants): string
    {
        $lines = [
            <<<SH
                mysql -e "CREATE USER '{$username}'@'{$host}' IDENTIFIED BY '{$password}';"
                SH,
        ];

        $lines[] = $this->mysqlGrantsCommand($username, $host, $grants, revokeAllFirst: false);

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function mysqlGrantsCommand(string $username, string $host, array $grants, bool $revokeAllFirst): string
    {
        $lines = [];

        if ($revokeAllFirst) {
            $lines[] = <<<SH
                mysql -e "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$username}'@'{$host}';"
                SH;
        }

        foreach ($grants as $database => $privileges) {
            $privilegeList = $this->privilegeList($privileges);
            $lines[] = <<<SH
                mysql -e "GRANT {$privilegeList} ON \\`{$database}\\`.* TO '{$username}'@'{$host}';"
                SH;
        }

        $lines[] = <<<'SH'
            mysql -e "FLUSH PRIVILEGES;"
            SH;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function postgresCreateCommand(string $username, string $password, array $grants): string
    {
        $lines = [
            <<<SH
                sudo -u postgres psql -c "CREATE ROLE \\"{$username}\\" WITH LOGIN PASSWORD '{$password}';"
                SH,
        ];

        foreach ($grants as $database => $privileges) {
            $privilegeList = $this->privilegeList($privileges);
            $lines[] = <<<SH
                sudo -u postgres psql -c "GRANT {$privilegeList} ON DATABASE \\"{$database}\\" TO \\"{$username}\\";"
                SH;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<int, string>>  $oldGrants
     * @param  array<string, array<int, string>>  $newGrants
     */
    private function postgresGrantsCommand(string $username, array $oldGrants, array $newGrants): string
    {
        $lines = [];

        foreach (array_keys($oldGrants) as $database) {
            $lines[] = <<<SH
                sudo -u postgres psql -c "REVOKE ALL PRIVILEGES ON DATABASE \\"{$database}\\" FROM \\"{$username}\\";"
                SH;
        }

        foreach ($newGrants as $database => $privileges) {
            $privilegeList = $this->privilegeList($privileges);
            $lines[] = <<<SH
                sudo -u postgres psql -c "GRANT {$privilegeList} ON DATABASE \\"{$database}\\" TO \\"{$username}\\";"
                SH;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function postgresDropCommand(string $username, array $grants): string
    {
        $lines = [];

        foreach (array_keys($grants) as $database) {
            $lines[] = <<<SH
                sudo -u postgres psql -c "REVOKE ALL PRIVILEGES ON DATABASE \\"{$database}\\" FROM \\"{$username}\\";"
                SH;
        }

        $lines[] = <<<SH
            sudo -u postgres psql -c "DROP ROLE IF EXISTS \\"{$username}\\";"
            SH;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function mongodbCreateCommand(string $username, string $password, array $grants): string
    {
        $roles = $this->mongoRoles($grants);

        return <<<SH
            mongosh --quiet --eval "db.getSiblingDB('admin').createUser({user: '{$username}', pwd: '{$password}', roles: {$roles}})"
            SH;
    }

    /**
     * @param  array<string, array<int, string>>  $grants
     */
    private function mongodbUpdateRolesCommand(string $username, array $grants): string
    {
        $roles = $this->mongoRoles($grants);

        return <<<SH
            mongosh --quiet --eval "db.getSiblingDB('admin').updateUser('{$username}', {roles: {$roles}})"
            SH;
    }

    /**
     * Render the MongoDB `roles` array literal, e.g. [{role: "readWrite", db: "app"}].
     *
     * @param  array<string, array<int, string>>  $grants
     */
    private function mongoRoles(array $grants): string
    {
        $roles = [];

        foreach ($grants as $database => $privileges) {
            $role = in_array('ALL', $privileges, true) ? 'dbOwner' : ($privileges[0] ?? 'read');
            $roles[] = "{role: '{$role}', db: '{$database}'}";
        }

        return '['.implode(', ', $roles).']';
    }

    /**
     * @param  array<int, string>  $privileges
     */
    private function privilegeList(array $privileges): string
    {
        return in_array('ALL', $privileges, true) ? 'ALL PRIVILEGES' : implode(', ', $privileges);
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
