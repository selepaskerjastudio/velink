<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationController extends Controller
{
    /**
     * Show the two-factor authentication settings page.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        $qrCodeSvg = null;

        if ($user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication()) {
            $qrCodeSvg = $this->qrCodeSvg($user);
        }

        return Inertia::render('settings/two-factor', [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'qrCodeSvg' => $qrCodeSvg,
            'secretKey' => $user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication()
                ? $user->two_factor_secret
                : null,
            'recoveryCodes' => $user->hasEnabledTwoFactorAuthentication()
                ? $user->two_factor_recovery_codes
                : null,
        ]);
    }

    /**
     * Generate a new two-factor secret and display the setup QR code.
     */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => (new Google2FA())->generateSecretKey(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back();
    }

    /**
     * Confirm two-factor authentication with the given TOTP code.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return back()->withErrors(['code' => 'Two-factor authentication has not been started.']);
        }

        $valid = (new Google2FA())->verifyKey($user->two_factor_secret, $request->string('code')->toString());

        if (! $valid) {
            return back()->withErrors(['code' => 'The provided two-factor code was invalid.']);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
        ])->save();

        return back();
    }

    /**
     * Regenerate the recovery codes for the user.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return back();
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
        ])->save();

        return back();
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back();
    }

    /**
     * Build the QR code SVG markup for the user's pending secret.
     */
    private function qrCodeSvg($user): string
    {
        $google2fa = new Google2FA();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $user->two_factor_secret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(192, 1),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($qrCodeUrl);
    }

    /**
     * Generate a fresh set of recovery codes.
     *
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }
}
