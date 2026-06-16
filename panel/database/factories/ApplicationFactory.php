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
        $slug = 'app_'.fake()->unique()->numberBetween(1, 99999);
        $user = 'velink';

        return [
            'server_id' => Server::factory(),
            'name' => $domain,
            'domain' => $domain,
            'app_type' => 'custom',
            'stack_mode' => 'production',
            'linux_user' => $user,
            'app_slug' => $slug,
            'root_path' => "/home/{$user}/webapps/{$slug}",
            'php_version' => '8.3',
            'branch' => 'main',
            'deploy_mode' => 'inplace',
            'status' => 'pending',
        ];
    }
}
