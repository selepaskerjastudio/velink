<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\SshKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Deploy and revoke SSH keys against a specific system user's authorized_keys.
 *
 * Both actions delegate the actual file write to SshKeyService (which rewrites
 * the whole authorized_keys from the pivot), keeping the DB as the single
 * source of truth.
 */
class ServerSshKeyController extends Controller
{
    public function __construct(private SshKeyService $service) {}

    public function deploy(Request $request, Server $server, SshKey $sshKey): RedirectResponse
    {
        abort_if($sshKey->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'system_user_id' => ['nullable', 'string'],
        ]);

        $targetUser = $this->resolveTargetUser($server, $validated['system_user_id'] ?? null);

        $this->service->deployToServer($sshKey, $server, $targetUser, $request->user()->id);

        AuditLogger::log(
            action: 'ssh_key.deployed',
            description: "SSH key '{$sshKey->name}' deployed to '{$targetUser->username}@{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: [
                'ssh_key_uuid' => $sshKey->uuid,
                'fingerprint' => $sshKey->fingerprint,
                'system_user' => $targetUser->username,
            ],
        );

        return redirect()->route('servers.ssh-keys', $server)->with('success', 'SSH key deployed.');
    }

    public function revoke(Request $request, Server $server, SshKey $sshKey): RedirectResponse
    {
        abort_if($sshKey->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'system_user_id' => ['nullable', 'string'],
        ]);

        $targetUser = $this->resolveTargetUser($server, $validated['system_user_id'] ?? null);

        $this->service->revokeFromUser($sshKey, $server, $targetUser, $request->user()->id);

        AuditLogger::log(
            action: 'ssh_key.revoked',
            description: "SSH key '{$sshKey->name}' revoked from '{$targetUser->username}@{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: [
                'ssh_key_uuid' => $sshKey->uuid,
                'fingerprint' => $sshKey->fingerprint,
                'system_user' => $targetUser->username,
            ],
        );

        return redirect()->route('servers.ssh-keys', $server)->with('success', 'SSH key revoked.');
    }

    /**
     * Resolve the target system user. If no UUID is provided (e.g. legacy
     * callers), fall back to the server's default admin so existing behaviour
     * keeps working.
     */
    private function resolveTargetUser(Server $server, ?string $systemUserUuid): SystemUser
    {
        if ($systemUserUuid) {
            $user = $server->systemUsers()->where('uuid', $systemUserUuid)->first();
            abort_if($user === null, 422, 'The selected system user was not found on this server.');
            return $user;
        }

        return $this->service->ensureDefaultAdmin($server);
    }
}
