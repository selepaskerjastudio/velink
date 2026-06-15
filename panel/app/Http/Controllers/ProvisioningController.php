<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\ProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvisioningController extends Controller
{
    public function store(Request $request, Server $server, ProvisionService $provisionService): RedirectResponse
    {
        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['string', 'in:'.implode(',', ProvisioningCatalog::COMPONENTS)],
            'php_versions' => [
                Rule::requiredIf(in_array('php', $request->input('components', []), true)),
                'array',
            ],
            'php_versions.*' => ['string', 'in:'.implode(',', ProvisioningCatalog::PHP_VERSIONS)],
        ]);

        $provisionService->provision(
            $server,
            $validated['components'],
            ['php_versions' => $validated['php_versions'] ?? []],
            $request->user()->id,
        );

        return redirect()->route('servers.show', $server);
    }
}
