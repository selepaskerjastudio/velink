<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AuditLogger;
use App\Services\ProvisionService;
use App\Services\ServiceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvisioningController extends Controller
{
    public function store(Request $request, Server $server, ProvisionService $provisionService, ServiceManager $serviceManager): RedirectResponse
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

        $phpVersions = $validated['php_versions'] ?? [];

        $provisionService->provision(
            $server,
            $validated['components'],
            ['php_versions' => $phpVersions],
            $request->user()->id,
        );

        $serviceManager->seedForServer($server, $validated['components'], $phpVersions);

        AuditLogger::log(
            action: 'server.provisioned',
            description: "Provisioning started on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['components' => $validated['components']],
        );

        return redirect()->route('servers.show', $server);
    }
}
