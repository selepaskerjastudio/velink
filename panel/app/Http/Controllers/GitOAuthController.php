<?php

namespace App\Http\Controllers;

use App\Models\GitCredential;
use App\Models\GitProvider;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GitOAuthController extends Controller
{
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['github', 'gitlab']), 404);

        // Store intended redirect in session so callback can return to it
        $request->session()->put('oauth_provider', $provider);

        return Socialite::driver($provider)->scopes($this->scopes($provider))->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['github', 'gitlab']), 404);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('git-credentials.index')
                ->withErrors(['oauth' => 'OAuth authentication failed. Please try again.']);
        }

        $gitProvider = GitProvider::firstOrCreate(
            ['type' => $provider],
            ['name' => ucfirst($provider)],
        );

        $request->user()->gitCredentials()->create([
            'git_provider_id' => $gitProvider->id,
            'account_username' => $socialUser->getNickname() ?? $socialUser->getName(),
            'access_token' => $socialUser->token,
        ]);

        AuditLogger::log(
            action: 'git_credential.created',
            description: "Git credential added via OAuth ({$provider} / ".($socialUser->getNickname() ?? '').')',
            userId: $request->user()->id,
            properties: ['provider_type' => $provider, 'method' => 'oauth'],
        );

        return redirect()->route('git-credentials.index');
    }

    private function scopes(string $provider): array
    {
        return match ($provider) {
            'github' => ['repo', 'read:user'],
            'gitlab' => ['read_user', 'read_repository'],
            default => [],
        };
    }
}
