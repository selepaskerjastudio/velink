<?php

namespace App\Http\Controllers;

use App\Models\DatabaseUser;
use App\Models\Server;
use App\Services\AuditLogger;
use App\Services\DatabaseUserProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DatabaseUserController extends Controller
{
    private const USERNAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,31}$/';

    private const HOST_REGEX = '/^(%|[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,62})?)$/';

    private const DB_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /**
     * Safe password charset — excludes quotes, backslash, $ and backtick so the
     * value can be interpolated into the single-quoted SQL/double-quoted shell
     * commands in DatabaseUserProvisionService without escaping.
     */
    private const PASSWORD_REGEX = '/^[A-Za-z0-9!@#%^*()_+=.\-]{8,64}$/';

    /**
     * Database users now live as a sub-tab of the unified Databases page.
     * This route is kept for back-compat and simply redirects there.
     */
    public function index(Server $server): RedirectResponse
    {
        return redirect()->route('databases.index', $server);
    }

    public function store(Request $request, Server $server, DatabaseUserProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(['mariadb', 'postgres', 'mongodb'])],
            'username' => [
                'required',
                'string',
                'regex:'.self::USERNAME_REGEX,
            ],
            'host' => ['required', 'string', 'max:60', 'regex:'.self::HOST_REGEX],
            'password' => ['nullable', 'string', 'regex:'.self::PASSWORD_REGEX],
            'grants' => ['nullable', 'array', $this->grantsRule($server, $request->input('engine'))],
        ]);

        $exists = $server->databaseUsers()
            ->where('engine', $validated['engine'])
            ->where('username', $validated['username'])
            ->where('host', $validated['host'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'username' => 'A database user with this username and host already exists for this engine.',
            ]);
        }

        $result = $service->create(
            $server,
            $validated['engine'],
            $validated['username'],
            $validated['host'],
            $validated['grants'] ?? [],
            $request->user()->id,
            $validated['password'] ?? null,
        );

        AuditLogger::log(
            action: 'database_user.created',
            description: "DB user '{$validated['username']}' created",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('databases.index', $server)->with([
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

        return redirect()->route('databases.index', $databaseUser->server);
    }

    public function resetPassword(Request $request, DatabaseUser $databaseUser, DatabaseUserProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['nullable', 'string', 'regex:'.self::PASSWORD_REGEX],
        ]);

        $result = $service->updatePassword($databaseUser, $validated['password'] ?? null, $request->user()->id);

        AuditLogger::log(
            action: 'database_user.password_reset',
            description: "DB user '{$databaseUser->username}' password reset",
            userId: $request->user()->id,
            serverId: $databaseUser->server_id,
        );

        return redirect()->route('databases.index', $databaseUser->server)->with([
            'plain_db_user_password' => $result['plainPassword'],
            'plain_db_user_username' => $databaseUser->username,
        ]);
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

        return redirect()->route('databases.index', $server);
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
            $databaseNames = $server->databases()->where('engine', $engine)->pluck('name')->all();

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
