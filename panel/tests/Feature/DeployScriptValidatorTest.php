<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\DeployScriptValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rm -rf / is rejected', function () {
    $warnings = DeployScriptValidator::check('rm -rf /');

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('Destructive');
});

test('rm -rf /* is rejected', function () {
    $warnings = DeployScriptValidator::check('rm -rf /*');

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain('Destructive');
});

test('dd to block device is rejected', function () {
    $warnings = DeployScriptValidator::check('dd if=/dev/zero of=/dev/sda');

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain("'dd'");
});

test('mkfs is rejected', function () {
    $warnings = DeployScriptValidator::check('mkfs.ext4 /dev/sdb1');

    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toContain("'mkfs'");
});

test('normal deploy script passes validation', function () {
    $warnings = DeployScriptValidator::check('git pull && composer install --no-dev');

    expect($warnings)->toBeEmpty();
});

test('update deploy settings rejects dangerous script', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'git_credential_id' => null,
        'deploy_script' => 'rm -rf /*',
    ]);

    $response->assertSessionHasErrors('deploy_script');
});

test('update deploy settings accepts safe script', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'git_credential_id' => null,
        'deploy_script' => 'git pull && composer install --no-dev && php artisan migrate --force',
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->refresh()->deploy_script)->toBe('git pull && composer install --no-dev && php artisan migrate --force');
});
