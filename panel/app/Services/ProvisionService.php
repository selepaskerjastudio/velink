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
    public function provision(Server $server, array $components, array $opts = [], ?int $userId = null, bool $includeBase = true): array
    {
        // Flatten every component's steps into one phased batch. Steps in the
        // same phase run concurrently (fast); phases enforce the dependencies:
        //   0 base → 1 services + PHP PPA → 2 PHP installs → 3 composer.
        // Single-component installs on an already-provisioned server skip base.
        $ordered = $includeBase
            ? $this->order($components)
            : array_values(array_filter(array_unique($components), fn ($c) => $c !== 'base'));

        $steps = [];
        foreach ($ordered as $component) {
            foreach ($this->catalog->steps($component, $opts) as $step) {
                $step['phase'] = $this->phaseFor($component, $step);
                $steps[] = $step;
            }
        }

        return $this->dispatcher->queueBatch($server, $steps, $userId);
    }

    /**
     * Dependency phase for a provisioning step. Everything in a phase installs
     * in parallel; later phases wait for earlier ones to finish.
     *
     * @param  array{name?: string, type: string, params: array<string, mixed>}  $step
     */
    private function phaseFor(string $component, array $step): int
    {
        return match ($component) {
            'base' => 0,
            'composer' => 3, // needs PHP
            'php' => str_contains($step['name'] ?? '', 'PPA') ? 1 : 2, // PPA first, then installs
            default => 1,     // nginx, certbot, redis, supervisor, node, databases
        };
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
