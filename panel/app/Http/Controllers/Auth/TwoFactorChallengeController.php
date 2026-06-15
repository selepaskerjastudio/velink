<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    /**
     * Show the two-factor authentication challenge page.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    /**
     * Verify the two-factor authentication challenge.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        $user = User::findOrFail($request->session()->get('login.id'));

        if ($code = $request->string('code')->toString()) {
            $valid = (new Google2FA())->verifyKey($user->two_factor_secret, $code);

            if (! $valid) {
                throw ValidationException::withMessages([
                    'code' => 'The provided two-factor code was invalid.',
                ]);
            }
        } elseif ($recoveryCode = $request->string('recovery_code')->toString()) {
            $recoveryCodes = $user->two_factor_recovery_codes ?? [];

            if (! in_array($recoveryCode, $recoveryCodes, true)) {
                throw ValidationException::withMessages([
                    'recovery_code' => 'The provided recovery code was invalid.',
                ]);
            }

            $user->forceFill([
                'two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$recoveryCode])),
            ])->save();
        } else {
            throw ValidationException::withMessages([
                'code' => 'Please provide an authentication code or recovery code.',
            ]);
        }

        $remember = $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
