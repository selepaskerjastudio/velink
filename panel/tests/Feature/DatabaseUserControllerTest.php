<?php

use App\Models\DatabaseInstance;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockDbUserGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();
    $databaseUser = DatabaseUser::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'username' => 'alice',
        'password' => 'secret',
        'host' => '%',
    ]);

    $this->get(route('database-users.index', $server))->assertRedirect('/login');
    $this->post(route('database-users.store', $server), [])->assertRedirect('/login');
    $this->patch(route('database-users.grants', $databaseUser), [])->assertRedirect('/login');
    $this->delete(route('database-users.destroy', $databaseUser))->assertRedirect('/login');
});

test('a database user can be created', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => '%',
    ]);

    $response->assertRedirect(route('database-users.index', $server));

    $databaseUser = DatabaseUser::where('server_id', $server->id)->first();
    expect($databaseUser)->not->toBeNull();
    expect($databaseUser->engine)->toBe('mysql');
    expect($databaseUser->username)->toBe('appuser');
    expect($databaseUser->host)->toBe('%');
    expect($databaseUser->grants)->toBe([]);

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain('CREATE USER');
    expect($published[0]['payload']['params']['command'])->toContain('appuser');
    expect($published[0]['payload']['params']['command'])->toContain('FLUSH PRIVILEGES');
});

test('creating a user flashes the plain password and username', function () {
    mockDbUserGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => '%',
    ]);

    $this->get(route('database-users.index', $server))
        ->assertInertia(fn ($page) => $page
            ->where('flash.plainDbUserPassword', fn ($val) => is_string($val) && strlen($val) === 24)
            ->where('flash.plainDbUserUsername', 'appuser')
        );
});

test('creating a user rejects invalid usernames', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => '1startsWithDigit',
        'host' => '%',
    ]);
    $response->assertSessionHasErrors('username');

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'user with spaces',
        'host' => '%',
    ]);
    $response->assertSessionHasErrors('username');

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'user;injection',
        'host' => '%',
    ]);
    $response->assertSessionHasErrors('username');

    expect(DatabaseUser::where('server_id', $server->id)->count())->toBe(0);
});

test('creating a user rejects invalid hosts', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => "'; DROP TABLE database_users;--",
    ]);
    $response->assertSessionHasErrors('host');

    expect(DatabaseUser::where('server_id', $server->id)->count())->toBe(0);
});

test('username and host must be unique per server', function () {
    mockDbUserGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseUser::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'username' => 'appuser',
        'password' => 'secret',
        'host' => '%',
    ]);

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => '%',
    ]);

    $response->assertSessionHasErrors('username');
    expect(DatabaseUser::where('server_id', $server->id)->count())->toBe(1);
});

test('grants must reference databases that exist on this server', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => '%',
        'grants' => ['nonexistent_db' => ['ALL']],
    ]);

    $response->assertSessionHasErrors('grants');
    expect(DatabaseUser::where('server_id', $server->id)->count())->toBe(0);
});

test('grants accept databases that exist on the server', function () {
    mockDbUserGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'name' => 'myapp',
    ]);

    $response = $this->post(route('database-users.store', $server), [
        'engine' => 'mysql',
        'username' => 'appuser',
        'host' => '%',
        'grants' => ['myapp' => ['ALL']],
    ]);

    $response->assertRedirect(route('database-users.index', $server));

    $databaseUser = DatabaseUser::where('server_id', $server->id)->first();
    expect($databaseUser->grants)->toBe(['myapp' => ['ALL']]);
});

test('grants can be updated', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'name' => 'myapp',
    ]);

    $databaseUser = DatabaseUser::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'username' => 'appuser',
        'password' => 'secret',
        'host' => '%',
        'grants' => [],
    ]);

    $response = $this->patch(route('database-users.grants', $databaseUser), [
        'grants' => ['myapp' => ['ALL']],
    ]);

    $response->assertRedirect(route('database-users.index', $server));
    expect($databaseUser->refresh()->grants)->toBe(['myapp' => ['ALL']]);

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain('REVOKE');
    expect($published[0]['payload']['params']['command'])->toContain('GRANT');
    expect($published[0]['payload']['params']['command'])->toContain('myapp');
});

test('a database user can be deleted', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $databaseUser = DatabaseUser::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'username' => 'appuser',
        'password' => 'secret',
        'host' => '%',
        'grants' => [],
    ]);

    $response = $this->delete(route('database-users.destroy', $databaseUser));

    $response->assertRedirect(route('database-users.index', $server));
    expect(DatabaseUser::find($databaseUser->id))->toBeNull();

    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain('DROP USER');
    expect($published[0]['payload']['params']['command'])->toContain('appuser');
});

test('index renders with databaseUsers, databases, and jobs props', function () {
    mockDbUserGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'name' => 'myapp',
    ]);

    DatabaseUser::create([
        'server_id' => $server->id,
        'engine' => 'mysql',
        'username' => 'appuser',
        'password' => 'secret',
        'host' => '%',
        'grants' => ['myapp' => ['ALL']],
    ]);

    $response = $this->get(route('database-users.index', $server));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('databaseUsers', 1)
        ->has('databases', 1)
        ->where('server.name', $server->name)
        ->where('databaseUsers.0.username', 'appuser')
        ->where('databaseUsers.0.host', '%')
    );
});
