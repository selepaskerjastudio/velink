<?php

namespace App\Http\Controllers;

use App\Models\DatabaseUser;
use App\Models\Server;
use App\Services\AuditLogger;
use App\Services\DatabaseUserProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseUserController extends Controller
{
    private const USERNAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,31}$/';

    private const HOST_REGEX = '/^(%|[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,62})?)$/';

    private const DB_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    public function index(Server $server): Response
    {
        return Inertia::render('servers/database-users', [
            'server' => [
                'id' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->public_ip,
                'status' => $server->status,
            ],
            'databaseUsers' => $server->databaseUsers()
                ->orderBy('username')
                ->get(['uuid', 'engine', 'username', 'host', 'grants'])
                ->map(fn ($u) => [
                    'id'       => $u->uuid,
                    'engine'   => $u->engine,
                    'username' => $u->username,
                    'host'     => $u->host,
                    'grants'   => $u->grants,
                ]),
            'databases' => $server->databases()
                ->orderBy('name')
                ->get(['uuid', 'engine', 'name'])
                ->map(fn ($d) => [
                    'id'     => $d->uuid,
                    'engine' => $d->engine,
                    'name'   => $d->name,
                ]),
            'jobs' => $server->agentJobs()
                ->where('type', 'shell')
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function store(Request $request, Server $server, DatabaseUserProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(['mysql', 'mariadb', 'postgres', 'mongodb'])],
            'username' => [
                'required',
                'string',
                'regex:'.self::USERNAME_REGEX,
            ],
            'host' => ['required', 'string', 'max:60', 'regex:'.self::HOST_REGEX],
            'grants' => ['nullable', 'array', $this->grantsRule($server, $request->input('engine'))],
        ]);

        $exists = $server->databaseUsers()
            ->where('username', $validated['username'])
            ->where('host', $validated['host'])
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => 'A database user with this username and host already exists on this server.',
            ]);
        }

        $result = $service->create(
            $server,
            $validated['engine'],
            $validated['username'],
            $validated['host'],
            $validated['grants'] ?? [],
            $request->user()->id,
        );

        AuditLogger::log(
            action: 'database_user.created',
            description: "DB user '{$validated['username']}' created",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('database-users.index', $server)->with([
            'plain_db_user_password' => $result['plainPassword'],
            'plain_db_user_username' => $validated['username'],
        ]);
    }

    public function grants(Request $request, DatabaseUser $databaseUser, DatabaseUserProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'grants' => ['nullable', 'array', $this->grantsRule($databaseUser->server, $databaseUser->engine)],
        ]);

        $service->updateGrants($databaseUser, $validated['grants'] ?? [], $request->user()->id);

        AuditLogger::log(
            action: 'database_user.updated',
            description: "DB user '{$databaseUser->username}' grants updated",
            userId: $request->user()->id,
            serverId: $databaseUser->server_id,
        );

        return redirect()->route('database-users.index', $databaseUser->server);
    }

    public function destroy(DatabaseUser $databaseUser, DatabaseUserProvisionService $service): RedirectResponse
    {
        $server = $databaseUser->server;
        $username = $databaseUser->username;
        $serverId = $databaseUser->server_id;

        $service->delete($databaseUser, request()->user()->id);

        AuditLogger::log(
            action: 'database_user.deleted',
            description: "DB user '{$username}' deleted",
            userId: request()->user()->id,
            serverId: $serverId,
        );

        return redirect()->route('database-users.index', $server);
    }

    /**
     * Build a closure rule validating `grants`: keys must be valid database
     * names that exist on this server, values must be non-empty arrays drawn
     * from the engine's privilege allowlist.
     */
    private function grantsRule(Server $server, ?string $engine): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($server, $engine) {
            if (! is_array($value)) {
                return;
            }

            $allowed = DatabaseUserProvisionService::PRIVILEGES[$engine] ?? [];
            $databaseNames = $server->databases()->pluck('name')->all();

            foreach ($value as $database => $privileges) {
                if (! is_string($database) || preg_match(self::DB_NAME_REGEX, $database) !== 1) {
                    $fail("Invalid database name: {$database}");

                    continue;
                }

                if (! in_array($database, $databaseNames, true)) {
                    $fail("Unknown database: {$database}");

                    continue;
                }

                if (! is_array($privileges) || $privileges === []) {
                    $fail("Grants for {$database} must be a non-empty list of privileges.");

                    continue;
                }

                foreach ($privileges as $privilege) {
                    if (! in_array($privilege, $allowed, true)) {
                        $fail("Invalid privilege \"{$privilege}\" for {$database}.");
                    }
                }
            }
        };
    }
}
