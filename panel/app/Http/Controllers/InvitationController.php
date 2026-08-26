<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    /**
     * Issue an invite link. Mail delivery is best-effort — the panel has no
     * mail server configured by default (MAIL_MAILER=log), so the primary
     * path is the copyable link flashed back to the admin, the same pattern
     * already used for the agent install command and DB credentials.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in([User::ROLE_ADMIN, User::ROLE_MEMBER])],
            'server_uuids' => ['array'],
            'server_uuids.*' => ['string'],
        ]);

        abort_if(
            User::query()->where('email', $validated['email'])->exists(),
            422,
            'A user with this email already exists.'
        );

        abort_if(
            UserInvitation::query()->where('email', $validated['email'])
                ->whereNull('accepted_at')->where('expires_at', '>', now())->exists(),
            422,
            'An invitation is already pending for this email.'
        );

        $serverIds = Server::query()->whereIn('uuid', $validated['server_uuids'] ?? [])->pluck('id')->all();
        $plainToken = Str::random(64);

        $invitation = UserInvitation::create([
            'uuid' => (string) Str::uuid(),
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => UserInvitation::hashToken($plainToken),
            'server_ids' => $serverIds,
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $inviteUrl = route('invitations.accept', $plainToken);

        try {
            if (config('mail.default') !== 'log') {
                Mail::raw(
                    "You've been invited to Velink as a {$validated['role']}. Accept your invitation: {$inviteUrl}\n\nThis link expires in 7 days.",
                    fn ($message) => $message->to($validated['email'])->subject('You have been invited to Velink')
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }

        AuditLogger::log(
            action: 'user.invited',
            description: "Invited '{$validated['email']}' as {$validated['role']}",
            userId: $request->user()->id,
            properties: ['email' => $validated['email'], 'role' => $validated['role'], 'server_ids' => $serverIds],
        );

        return redirect()->route('users.index')->with(['invite_url' => $inviteUrl]);
    }

    public function destroy(Request $request, UserInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 422, 'This invitation was already accepted.');

        $email = $invitation->email;

        $invitation->delete();

        AuditLogger::log(
            action: 'user.invite_revoked',
            description: "Revoked invitation for '{$email}'",
            userId: $request->user()->id,
            properties: ['email' => $email],
        );

        return redirect()->route('users.index');
    }
}
