<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;

/**
 * Turns a set of selected components into a sequence of dispatched AgentJobs.
 * "base" is always provisioned first so package repos/tools are present.
 */
class ProvisionService
{
    public function __construct(
        private JobDispatcher $dispatcher,
        private ProvisioningCatalog $catalog,
    ) {
    }

    /**
     * @param  array<int, string>  $components
     * @param  array<string, mixed>  $opts
     * @return array<int, AgentJob>
     */
    public function provision(Server $server, array $components, array $opts = [], ?int $userId = null): array
    {
        $jobs = [];

        foreach ($this->order($components) as $component) {
            foreach ($this->catalog->steps($component, $opts) as $step) {
                // The job type must be the executor action ('shell'); the
                // component/step name is surfaced in the step's output header.
                $jobs[] = $this->dispatcher->dispatch(
                    $server,
                    $step['type'],
                    $step['params'],
                    ['user_id' => $userId],
                );
            }
        }

        return $jobs;
    }

    /**
     * Ensure "base" runs first and de-duplicate while preserving order.
     *
     * @param  array<int, string>  $components
     * @return array<int, string>
     */
    private function order(array $components): array
    {
        $components = array_values(array_unique($components));
        $ordered = in_array('base', $components, true) || $components !== []
            ? ['base']
            : [];

        foreach ($components as $component) {
            if ($component !== 'base') {
                $ordered[] = $component;
            }
        }

        return $ordered;
    }
}
