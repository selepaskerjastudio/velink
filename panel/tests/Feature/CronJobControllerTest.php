<?php

use App\Models\Application;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockCronGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();
    $cronJob = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $this->get(route('cron.index', $server))->assertRedirect('/login');
    $this->post(route('cron.store', $server), [])->assertRedirect('/login');
    $this->patch(route('cron.update', $cronJob), [])->assertRedirect('/login');
    $this->post(route('cron.toggle', $cronJob))->assertRedirect('/login');
    $this->delete(route('cron.destroy', $cronJob))->assertRedirect('/login');
});

test('a cron job can be created', function () {
    mockCronGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('cron.store', $server), [
        'user' => 'www-data',
        'command' => 'php /var/www/artisan schedule:run',
        'schedule' => '* * * * *',
    ]);

    $response->assertRedirect(route('cron.index', $server));

    $cronJob = CronJob::where('server_id', $server->id)->first();
    expect($cronJob)->not->toBeNull();
    expect($cronJob->user)->toBe('www-data');
    expect($cronJob->command)->toBe('php /var/www/artisan schedule:run');
    expect($cronJob->schedule)->toBe('* * * * *');
    expect($cronJob->status)->toBe('active');
    expect($cronJob->application_id)->toBeNull();

    expect($server->agentJobs()->where('type', 'render_config')->count())->toBe(1);
});

test('the sync job includes active cron jobs in the render_config vars', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->post(route('cron.store', $server), [
        'user' => 'www-data',
        'command' => 'php artisan schedule:run',
        'schedule' => '5 * * * *',
    ]);

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('render_config');

    $vars = $published[0]['payload']['params']['vars'];
    expect($vars)->toHaveKey('jobs');
    expect($vars['jobs'])->toHaveCount(1);
    expect($vars['jobs'][0]['schedule'])->toBe('5 * * * *');
    expect($vars['jobs'][0]['user'])->toBe('www-data');
    expect($vars['jobs'][0]['command'])->toBe('php artisan schedule:run');
});

test('creating a cron job rejects commands with newlines', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('cron.store', $server), [
        'user' => 'root',
        'command' => "php artisan schedule:run\nrm -rf /",
        'schedule' => '* * * * *',
    ]);

    $response->assertSessionHasErrors('command');
    expect(CronJob::where('server_id', $server->id)->count())->toBe(0);
});

test('creating a cron job rejects invalid schedule strings', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('cron.store', $server), [
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => 'not a cron',
    ]);

    $response->assertSessionHasErrors('schedule');

    $response = $this->post(route('cron.store', $server), [
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * *',
    ]);

    $response->assertSessionHasErrors('schedule');
});

test('creating a cron job rejects invalid user names', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('cron.store', $server), [
        'user' => 'root; evil',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
    ]);

    $response->assertSessionHasErrors('user');
});

test('cron job application_id must belong to the server', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $foreignApp = Application::factory()->create();

    $response = $this->post(route('cron.store', $server), [
        'application_id' => $foreignApp->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
    ]);

    $response->assertSessionHasErrors('application_id');
});

test('toggle flips status and syncs cron file', function () {
    mockCronGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $cronJob = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $this->post(route('cron.toggle', $cronJob))->assertRedirect(route('cron.index', $server));
    expect($cronJob->refresh()->status)->toBe('paused');

    $this->post(route('cron.toggle', $cronJob))->assertRedirect(route('cron.index', $server));
    expect($cronJob->refresh()->status)->toBe('active');

    expect($server->agentJobs()->where('type', 'render_config')->count())->toBe(2);
});

test('paused cron jobs are excluded from the sync vars', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $alwaysActive = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $toBeToggled = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan toggleable:command',
        'schedule' => '0 1 * * *',
        'status' => 'active',
    ]);

    // Toggle the second job to paused; the sync job vars should omit it.
    $this->post(route('cron.toggle', $toBeToggled));

    expect($toBeToggled->refresh()->status)->toBe('paused');

    $lastPublished = end($published);
    $jobs = $lastPublished['payload']['params']['vars']['jobs'];
    $commands = array_column($jobs, 'command');
    expect($commands)->toContain('php artisan schedule:run');
    expect($commands)->not->toContain('php artisan toggleable:command');
});

test('a cron job can be updated', function () {
    mockCronGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $cronJob = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $response = $this->patch(route('cron.update', $cronJob), [
        'user' => 'www-data',
        'command' => 'php artisan horizon',
        'schedule' => '0 2 * * *',
    ]);

    $response->assertRedirect(route('cron.index', $server));

    $cronJob->refresh();
    expect($cronJob->user)->toBe('www-data');
    expect($cronJob->command)->toBe('php artisan horizon');
    expect($cronJob->schedule)->toBe('0 2 * * *');
});

test('a cron job can be deleted and syncs the cron file', function () {
    mockCronGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $cronJob = CronJob::create([
        'server_id' => $server->id,
        'user' => 'root',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $response = $this->delete(route('cron.destroy', $cronJob));

    $response->assertRedirect(route('cron.index', $server));
    expect(CronJob::find($cronJob->id))->toBeNull();
    expect($server->agentJobs()->where('type', 'render_config')->count())->toBe(1);
});

test('index renders with cronJobs and applications props', function () {
    mockCronGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->for($server)->create();

    CronJob::create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'user' => $application->linux_user,
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $response = $this->get(route('cron.index', $server));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('cronJobs', 1)
        ->has('applications', 1)
        ->where('server.name', $server->name)
        ->where('cronJobs.0.user', $application->linux_user)
        ->where('cronJobs.0.application_name', $application->name)
    );
});
