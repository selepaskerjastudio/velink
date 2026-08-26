<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptController extends Controller
{
    /**
     * Deliberately outside RegistrationEnabled — that middleware only guards
     * the /register bootstrap path for the very first user. An invited user
     * has already been vetted by an admin issuing the invite, so they take
     * this separate, always-open path instead.
     */
    public function create(string $token): Response
    {
        $invitation = $this->findPendingInvitation($token);

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findPendingInvitation($token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
        ]);

        $user->forceFill([
            'role' => $invitation->role,
            'is_active' => true,
        ])->save();

        $user->servers()->sync($invitation->server_ids ?? []);

        $invitation->forceFill(['accepted_at' => now()])->save();

        AuditLogger::log(
            action: 'user.invite_accepted',
            description: "'{$user->email}' accepted their invitation",
            userId: $user->id,
            properties: ['role' => $invitation->role],
        );

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }

    /**
     * The token is not route-model-bound: an invalid one should 404 cleanly
     * rather than surface a binding exception, and lookup has to hash the
     * plaintext token first since it's stored as a sha256 digest.
     */
    private function findPendingInvitation(string $token): UserInvitation
    {
        return UserInvitation::query()
            ->where('token', UserInvitation::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}
