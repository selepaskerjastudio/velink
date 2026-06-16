<?php

use App\Models\DatabaseInstance;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockDbGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();
    $database = DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $this->get(route('databases.index', $server))->assertRedirect('/login');
    $this->post(route('databases.store', $server), [])->assertRedirect('/login');
    $this->delete(route('databases.destroy', $database))->assertRedirect('/login');
});

test('a MySQL database can be created', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mariadb',
        'name' => 'myapp',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $response->assertRedirect(route('databases.index', $server));

    $database = DatabaseInstance::where('server_id', $server->id)->first();
    expect($database)->not->toBeNull();
    expect($database->engine)->toBe('mariadb');
    expect($database->name)->toBe('myapp');
    expect($database->charset)->toBe('utf8mb4');

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain('CREATE DATABASE');
    expect($published[0]['payload']['params']['command'])->toContain('myapp');
    expect($published[0]['payload']['params']['command'])->toContain('utf8mb4');
});

test('a PostgreSQL database can be created', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->post(route('databases.store', $server), [
        'engine' => 'postgres',
        'name' => 'Myapp2',
        'charset' => 'UTF8',
    ]);

    $database = DatabaseInstance::where('server_id', $server->id)->first();
    expect($database)->not->toBeNull();
    expect($database->engine)->toBe('postgres');

    expect($published[0]['payload']['params']['command'])->toContain('CREATE DATABASE');
    expect($published[0]['payload']['params']['command'])->toContain('Myapp2');
    expect($published[0]['payload']['params']['command'])->toContain('psql');
});

test('a MongoDB database can be created', function () {
    mockDbGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mongodb',
        'name' => 'Myapp3',
    ]);

    $response->assertRedirect(route('databases.index', $server));

    $database = DatabaseInstance::where('server_id', $server->id)->first();
    expect($database)->not->toBeNull();
    expect($database->engine)->toBe('mongodb');
    expect($database->charset)->toBeNull();
});

test('creating a database rejects reserved database names', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    foreach (['mysql', 'postgres', 'information_schema', 'template0', 'admin'] as $reserved) {
        $response = $this->post(route('databases.store', $server), [
            'engine' => 'mariadb',
            'name' => $reserved,
        ]);

        $response->assertSessionHasErrors('name');
    }

    expect(DatabaseInstance::where('server_id', $server->id)->count())->toBe(0);
});

test('creating a database rejects invalid names', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mariadb',
        'name' => '1startsWithDigit',
    ]);
    $response->assertSessionHasErrors('name');

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mariadb',
        'name' => 'has spaces',
    ]);
    $response->assertSessionHasErrors('name');

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mariadb',
        'name' => 'shell; injection',
    ]);
    $response->assertSessionHasErrors('name');

    expect(DatabaseInstance::where('server_id', $server->id)->count())->toBe(0);
});

test('database names must be unique per server', function () {
    mockDbGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $response = $this->post(route('databases.store', $server), [
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $response->assertSessionHasErrors('name');
    expect(DatabaseInstance::where('server_id', $server->id)->count())->toBe(1);
});

test('the same database name is allowed on a different engine', function () {
    mockDbGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create(['server_id' => $server->id, 'engine' => 'mariadb', 'name' => 'app']);

    // Same name, different engine → allowed.
    $this->post(route('databases.store', $server), ['engine' => 'postgres', 'name' => 'app'])
        ->assertRedirect(route('databases.index', $server));

    expect(DatabaseInstance::where('server_id', $server->id)->where('name', 'app')->count())->toBe(2);
});

test('the same database name can be used on different servers', function () {
    mockDbGatewayPublish();

    $this->actingAs(User::factory()->create());
    $serverA = Server::factory()->online()->create();
    $serverB = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $serverA->id,
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $response = $this->post(route('databases.store', $serverB), [
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $response->assertRedirect(route('databases.index', $serverB));
    expect(DatabaseInstance::where('server_id', $serverB->id)->where('name', 'myapp')->exists())->toBeTrue();
});

test('a database can be deleted', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $database = DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'name' => 'myapp',
    ]);

    $response = $this->delete(route('databases.destroy', $database));

    $response->assertRedirect(route('databases.index', $server));
    expect(DatabaseInstance::find($database->id))->toBeNull();

    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain('DROP DATABASE');
    expect($published[0]['payload']['params']['command'])->toContain('myapp');
});

test('index renders with databases and jobs props', function () {
    mockDbGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'mariadb',
        'name' => 'alpha',
    ]);

    DatabaseInstance::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'name' => 'beta',
    ]);

    $response = $this->get(route('databases.index', $server));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('databases', 2)
        ->where('server.name', $server->name)
        ->where('databases.0.name', 'alpha')
        ->where('databases.1.name', 'beta')
    );
});
