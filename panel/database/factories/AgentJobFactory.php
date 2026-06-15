<?php

namespace Database\Factories;

use App\Models\AgentJob;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentJob>
 */
class AgentJobFactory extends Factory
{
    protected $model = AgentJob::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => 'shell',
            'payload' => ['command' => 'echo hi'],
            'status' => AgentJob::STATUS_DISPATCHED,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => AgentJob::STATUS_RUNNING]);
    }
}
