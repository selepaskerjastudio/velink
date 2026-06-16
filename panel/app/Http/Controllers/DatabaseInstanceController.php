<?php

namespace App\Http\Controllers;

use App\Models\DatabaseInstance;
use App\Models\Server;
use App\Services\AuditLogger;
use App\Services\DatabaseProvisionService;
use App\Support\DatabaseNaming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseInstanceController extends Controller
{
    public function index(Server $server): Response
    {
        // Only surface engine tabs for engines that are actually installed.
        $installedEngines = $server->installedDatabaseEngines();

        return Inertia::render('servers/databases', [
            'server' => [
                'id' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->public_ip,
                'status' => $server->status,
            ],
            'installedEngines' => $installedEngines,
            'databases' => $server->databases()
                ->orderBy('name')
                ->get(['uuid', 'engine', 'name', 'charset', 'collation', 'created_at'])
                ->map(fn ($d) => [
                    'id' => $d->uuid,
                    'engine' => $d->engine,
                    'name' => $d->name,
                    'charset' => $d->charset,
                    'collation' => $d->collation,
                    'created_at' => $d->created_at?->format('d M Y'),
                ]),
            'databaseUsers' => $server->databaseUsers()
                ->orderBy('username')
                ->get(['uuid', 'engine', 'username', 'host', 'grants'])
                ->map(fn ($u) => [
                    'id' => $u->uuid,
                    'engine' => $u->engine,
                    'username' => $u->username,
                    'host' => $u->host,
                    'grants' => $u->grants,
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

    public function store(Request $request, Server $server, DatabaseProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(['mariadb', 'postgres', 'mongodb'])],
            'name' => [
                'required',
                'string',
                'regex:'.DatabaseNaming::DB_NAME_REGEX,
                function ($attribute, $value, $fail) {
                    if (DatabaseNaming::isReserved((string) $value)) {
                        $fail('The '.$attribute.' is a reserved name.');
                    }
                },
                Rule::unique('databases', 'name')
                    ->where('server_id', $server->id)
                    ->where('engine', $request->input('engine')),
            ],
            'charset' => ['nullable', 'string', 'max:64', 'regex:'.DatabaseNaming::CHARSET_REGEX],
            'collation' => ['nullable', 'string', 'max:64', 'regex:'.DatabaseNaming::CHARSET_REGEX],
        ]);

        $service->create(
            $server,
            $validated['engine'],
            $validated['name'],
            $validated['charset'] ?? null,
            $validated['collation'] ?? null,
            $request->user()->id,
        );

        AuditLogger::log(
            action: 'database.created',
            description: "Database '{$validated['name']}' ({$validated['engine']}) created on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('databases.index', $server);
    }

    public function destroy(DatabaseInstance $database, DatabaseProvisionService $service): RedirectResponse
    {
        $server = $database->server;
        $databaseName = $database->name;
        $serverId = $database->server_id;

        $service->delete($database, request()->user()->id);

        AuditLogger::log(
            action: 'database.deleted',
            description: "Database '{$databaseName}' deleted",
            userId: request()->user()->id,
            serverId: $serverId,
        );

        return redirect()->route('databases.index', $server);
    }
}
