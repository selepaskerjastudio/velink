<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deployment>
 */
class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'user_id' => null,
            'commit_hash' => fake()->regexify('[a-f0-9]{7}'),
            'commit_message' => fake()->sentence(),
            'branch' => 'main',
            'mode' => 'inplace',
            'status' => 'pending',
            'triggered_by' => 'manual',
            'agent_job_uuid' => null,
            'log' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }
}
