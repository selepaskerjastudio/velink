<?php

namespace App\Http\Controllers;

use App\Models\SshKey;
use App\Services\AuditLogger;
use App\Services\SshKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SshKeyController extends Controller
{
    public function __construct(private SshKeyService $service) {}

    /**
     * List the authenticated user's SSH keys and the servers each is deployed to.
     */
    public function index(Request $request): Response
    {
        $sshKeys = $request->user()->sshKeys()
            ->with(['servers:id,uuid,name,public_ip'])
            ->latest('id')
            ->get(['id', 'uuid', 'name', 'fingerprint', 'type', 'comment', 'created_at'])
            ->map(fn (SshKey $key) => [
                'id' => $key->uuid,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'type' => $key->type,
                'comment' => $key->comment,
                'created_at' => $key->created_at,
                'servers' => $key->servers->map(fn ($s) => [
                    'id' => $s->uuid,
                    'name' => $s->name,
                    'public_ip' => $s->public_ip,
                ]),
            ]);

        return Inertia::render('settings/ssh-keys', [
            'sshKeys' => $sshKeys,
            'adminUser' => SshKeyService::DEFAULT_ADMIN_USERNAME,
        ]);
    }

    /**
     * Validate, parse, fingerprint and store a new SSH public key.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $parsed = $this->service->parsePublicKey($validated['public_key']);
            $fingerprint = $this->service->computeFingerprint($validated['public_key']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'public_key' => $e->getMessage(),
            ]);
        }

        $duplicate = SshKey::where('user_id', $request->user()->id)
            ->where('fingerprint', $fingerprint)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'public_key' => 'You already have an SSH key with this fingerprint.',
            ]);
        }

        $key = $request->user()->sshKeys()->create([
            'name' => $validated['name'],
            'public_key' => $validated['public_key'],
            'fingerprint' => $fingerprint,
            'type' => $parsed['type'],
            'comment' => $parsed['comment'],
        ]);

        AuditLogger::log(
            action: 'ssh_key.created',
            description: "SSH key '{$key->name}' added ({$fingerprint})",
            userId: $request->user()->id,
            properties: ['fingerprint' => $fingerprint, 'type' => $parsed['type']],
        );

        return redirect()->route('ssh-keys.index');
    }

    /**
     * Delete a key and rebuild authorized_keys on every server/user it was on.
     */
    public function destroy(Request $request, SshKey $sshKey): RedirectResponse
    {
        abort_if($sshKey->user_id !== $request->user()->id, 403);

        // Capture the (server, system_user) pairs that currently carry this key
        // BEFORE detaching — otherwise the rebuild has no rows to read.
        $deployments = \DB::table('server_ssh_key')
            ->where('ssh_key_id', $sshKey->id)
            ->get(['server_id', 'system_user_id']);

        $sshKey->servers()->detach();
        $sshKey->delete();

        // Rebuild each affected user's authorized_keys directly — syncUserKeys
        // reads the pivot, which now (correctly) excludes this key, so the
        // rewritten file reflects the remaining keys (or an empty file).
        foreach ($deployments as $deployment) {
            $server = \App\Models\Server::find($deployment->server_id);
            $targetUser = \App\Models\SystemUser::find($deployment->system_user_id);
            if ($server && $targetUser) {
                $this->service->syncUserKeys($server, $targetUser, $request->user()->id);
            }
        }

        $affectedServers = $deployments->pluck('server_id')->unique();
        AuditLogger::log(
            action: 'ssh_key.deleted',
            description: "SSH key '{$sshKey->name}' removed and undeployed from {$affectedServers->count()} server(s)",
            userId: $request->user()->id,
        );

        return redirect()->route('ssh-keys.index');
    }
}
