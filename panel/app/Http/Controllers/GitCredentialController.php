<?php

namespace App\Http\Controllers;

use App\Models\GitCredential;
use App\Models\GitProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GitCredentialController extends Controller
{
    private const PROVIDER_TYPES = ['github', 'gitlab'];

    public function index(Request $request): Response
    {
        return Inertia::render('git-credentials/index', [
            'credentials' => $request->user()->gitCredentials()
                ->with('provider:id,type,name')
                ->latest('id')
                ->get(['id', 'uuid', 'git_provider_id', 'account_username', 'created_at'])
                ->map(fn ($c) => [
                    'id' => $c->uuid,
                    'account_username' => $c->account_username,
                    'created_at' => $c->created_at,
                    'provider' => ['type' => $c->provider->type, 'name' => $c->provider->name],
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'in:'.implode(',', self::PROVIDER_TYPES)],
            'account_username' => ['nullable', 'string', 'max:255'],
            'access_token' => ['required', 'string', 'max:1024'],
        ]);

        $provider = GitProvider::firstOrCreate(
            ['type' => $validated['provider_type']],
            ['name' => ucfirst($validated['provider_type'])],
        );

        $request->user()->gitCredentials()->create([
            'git_provider_id' => $provider->id,
            'account_username' => $validated['account_username'] ?? null,
            'access_token' => $validated['access_token'],
        ]);

        return redirect()->route('git-credentials.index');
    }

    public function destroy(Request $request, GitCredential $gitCredential): RedirectResponse
    {
        abort_if($gitCredential->user_id !== $request->user()->id, 403);

        $gitCredential->delete();

        return redirect()->route('git-credentials.index');
    }
}
