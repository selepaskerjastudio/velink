<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List every user and pending invitation. Admin-only (see routes/users.php),
     * so no visibility scoping is needed — an admin manages the whole roster.
     */
    public function index(): Response
    {
        $users = User::query()
            ->with('servers:servers.id,uuid')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'server_ids' => $user->servers->pluck('uuid'),
                'created_at' => $user->created_at,
            ]);

        $servers = Server::query()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (Server $server) => ['id' => $server->uuid, 'name' => $server->name]);

        $invitations = UserInvitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserInvitation $invitation) => [
                'id' => $invitation->uuid,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'invited_by' => $invitation->invitedBy?->name,
                'expires_at' => $invitation->expires_at,
            ]);

        return Inertia::render('settings/users', [
            'users' => $users,
            'servers' => $servers,
            'invitations' => $invitations,
        ]);
    }

    /**
     * Update a user's role and/or active status.
     *
     * The two self-guards below are the entire lockout defense, and they're
     * sufficient on their own: reaching zero active admins requires the LAST
     * active admin to act on themselves (every other action leaves the actor
     * — necessarily an active admin, since EnsureUserIsActive and the 'admin'
     * middleware already ran — as at least one admin standing). A separate
     * "don't remove the last admin" check has no request that can reach it:
     * either it's a self-action (caught here first) or a different admin is
     * acting, in which case that admin is themselves proof one remains.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([User::ROLE_ADMIN, User::ROLE_MEMBER])],
            'is_active' => ['required', 'boolean'],
        ]);

        abort_if(
            $user->is($request->user()) && $validated['role'] !== User::ROLE_ADMIN,
            422,
            'You cannot demote yourself.'
        );

        abort_if(
            $user->is($request->user()) && ! $validated['is_active'],
            422,
            'You cannot deactivate yourself.'
        );

        $fromRole = $user->role;
        $fromActive = $user->is_active;

        $user->forceFill($validated)->save();

        if ($fromRole !== $validated['role']) {
            AuditLogger::log(
                action: 'user.role_changed',
                description: "Role for '{$user->email}' changed from '{$fromRole}' to '{$validated['role']}'",
                userId: $request->user()->id,
                properties: ['target_user_uuid' => $user->uuid, 'from' => $fromRole, 'to' => $validated['role']],
            );
        }

        if ($fromActive !== $validated['is_active']) {
            AuditLogger::log(
                action: $validated['is_active'] ? 'user.reactivated' : 'user.deactivated',
                description: ($validated['is_active'] ? 'Reactivated' : 'Deactivated')." '{$user->email}'",
                userId: $request->user()->id,
                properties: ['target_user_uuid' => $user->uuid],
            );
        }

        return redirect()->route('users.index');
    }

    /**
     * Replace a user's server assignments. Ignored entirely for admins, who
     * already see and manage every server regardless of assignment.
     */
    public function syncServers(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'server_uuids' => ['array'],
            'server_uuids.*' => ['string'],
        ]);

        $serverIds = Server::query()->whereIn('uuid', $validated['server_uuids'] ?? [])->pluck('id');

        $changes = $user->servers()->sync($serverIds);

        $addedUuids = Server::query()->whereIn('id', $changes['attached'])->pluck('uuid');
        $removedUuids = Server::query()->whereIn('id', $changes['detached'])->pluck('uuid');

        foreach ($addedUuids as $serverUuid) {
            AuditLogger::log(
                action: 'user.server_assigned',
                description: "Assigned '{$user->email}' to server",
                userId: $request->user()->id,
                properties: ['target_user_uuid' => $user->uuid, 'server_uuid' => $serverUuid],
            );
        }

        foreach ($removedUuids as $serverUuid) {
            AuditLogger::log(
                action: 'user.server_unassigned',
                description: "Unassigned '{$user->email}' from server",
                userId: $request->user()->id,
                properties: ['target_user_uuid' => $user->uuid, 'server_uuid' => $serverUuid],
            );
        }

        return redirect()->route('users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Same reasoning as update(): this alone prevents ever reaching zero
        // admins, since the acting admin (guaranteed active by middleware)
        // survives any deletion that isn't of themselves.
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');

        $email = $user->email;
        $role = $user->role;

        $user->delete();

        AuditLogger::log(
            action: 'user.deleted',
            description: "Deleted user '{$email}'",
            userId: $request->user()->id,
            properties: ['email' => $email, 'role' => $role],
        );

        return redirect()->route('users.index');
    }
}
