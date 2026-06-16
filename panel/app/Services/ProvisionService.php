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
    ) {}

    /**
     * @param  array<int, string>  $components
     * @param  array<string, mixed>  $opts
     * @return array<int, AgentJob>
     */
    public function provision(Server $server, array $components, array $opts = [], ?int $userId = null): array
    {
        // Flatten every component's ordered steps into one list, then run them
        // as a single sequential batch. The agent executes jobs concurrently,
        // so dispatching steps one-at-a-time (next only after the previous
        // succeeds) is what guarantees ordering — e.g. base before the PPA,
        // php before composer.
        $steps = [];
        foreach ($this->order($components) as $component) {
            foreach ($this->catalog->steps($component, $opts) as $step) {
                $steps[] = $step;
            }
        }

        return $this->dispatcher->queueSequential($server, $steps, $userId);
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
