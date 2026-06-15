<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name' => 'web-'.fake()->unique()->numberBetween(1, 99999),
            'hostname' => fake()->domainName(),
            'public_ip' => fake()->ipv4(),
            'status' => 'pending',
            'agent_token' => Str::random(48),
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['status' => 'online']);
    }
}
