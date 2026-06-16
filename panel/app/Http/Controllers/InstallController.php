<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Public, token-authenticated endpoints called by the agent installer script.
 *
 * Authentication is the per-server enrollment token (the same bcrypt-hashed
 * `agent_token` the gateway verifies). There is no session/CSRF here — the
 * caller is the server being installed, identified solely by server_id + token.
 */
class InstallController extends Controller
{
    /**
     * Re-arm auto-provisioning for a freshly (re)installed server.
     *
     * Clears the server's tracked systemd services and prior agent jobs so the
     * presence handler treats the next agent connect as a first connect and
     * dispatches the full provisioning stack — with the correct timing, since
     * jobs dispatched before the agent is online would be dropped by the
     * gateway. Safe to call repeatedly; on a brand-new server there is simply
     * nothing to clear.
     */
    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'token' => ['required', 'string'],
        ]);

        $server = Server::where('uuid', $data['server_id'])->first();

        if (! $server || ! Hash::check($data['token'], $server->agent_token)) {
            return response()->json(['ok' => false], 401);
        }

        $server->services()->where('type', 'systemd')->delete();
        $server->agentJobs()->delete();

        AuditLogger::log(
            action: 'server.provision_rearmed',
            description: "Provisioning re-armed via installer for '{$server->name}'",
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return response()->json(['ok' => true]);
    }
}
