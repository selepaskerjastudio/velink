<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        $domain = fake()->unique()->domainName();
        $linuxUser = 'app_'.fake()->unique()->numberBetween(1, 99999);

        return [
            'server_id' => Server::factory(),
            'name' => $domain,
            'domain' => $domain,
            'root_path' => "/home/{$linuxUser}",
            'linux_user' => $linuxUser,
            'php_version' => '8.3',
            'branch' => 'main',
            'deploy_mode' => 'inplace',
            'status' => 'pending',
        ];
    }
}
