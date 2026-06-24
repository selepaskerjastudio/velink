<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\SshKey;
use App\Services\AuditLogger;
use App\Services\SshKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Deploy and revoke SSH keys against a specific server's authorized_keys.
 *
 * Both actions delegate the actual file write to SshKeyService::syncServerKeys
 * (which rewrites the whole authorized_keys from the pivot), keeping the DB as
 * the single source of truth.
 */
class ServerSshKeyController extends Controller
{
    public function __construct(private SshKeyService $service) {}

    public function deploy(Request $request, Server $server, SshKey $sshKey): RedirectResponse
    {
        abort_if($sshKey->user_id !== $request->user()->id, 403);

        $this->service->deployToServer($sshKey, $server, $request->user()->id);

        AuditLogger::log(
            action: 'ssh_key.deployed',
            description: "SSH key '{$sshKey->name}' deployed to server '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['ssh_key_uuid' => $sshKey->uuid, 'fingerprint' => $sshKey->fingerprint],
        );

        return redirect()->route('servers.settings', $server)->with('success', 'SSH key deployed.');
    }

    public function revoke(Request $request, Server $server, SshKey $sshKey): RedirectResponse
    {
        abort_if($sshKey->user_id !== $request->user()->id, 403);

        $server->sshKeys()->detach($sshKey->id);
        $this->service->syncServerKeys($server, $request->user()->id);

        AuditLogger::log(
            action: 'ssh_key.revoked',
            description: "SSH key '{$sshKey->name}' revoked from server '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['ssh_key_uuid' => $sshKey->uuid, 'fingerprint' => $sshKey->fingerprint],
        );

        return redirect()->route('servers.settings', $server)->with('success', 'SSH key revoked.');
    }
}
