<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\AppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockReverseProxyPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('migration backfills behind_reverse_proxy from servers.uses_edge_proxy', function () {
    // Two servers: one behind the edge, one native.
    $edgeServer = Server::factory()->online()->create(['uses_edge_proxy' => true]);
    $nativeServer = Server::factory()->online()->create(['uses_edge_proxy' => false]);

    $edgeApp = Application::factory()->create(['server_id' => $edgeServer->id, 'behind_reverse_proxy' => false]);
    $nativeApp = Application::factory()->create(['server_id' => $nativeServer->id, 'behind_reverse_proxy' => false]);

    // Re-run the migration's backfill against the current DB state.
    \DB::table('applications as a')
        ->join('servers as s', 's.id', '=', 'a.server_id')
        ->where('s.uses_edge_proxy', true)
        ->update(['a.behind_reverse_proxy' => true]);

    expect($edgeApp->fresh()->behind_reverse_proxy)->toBeTrue();
    expect($nativeApp->fresh()->behind_reverse_proxy)->toBeFalse();
});

test('store defaults behind_reverse_proxy to the server uses_edge_proxy flag', function () {
    mockReverseProxyPublish();

    $this->actingAs(User::factory()->create());

    // Edge server → new app should default to behind_reverse_proxy=true.
    $edgeServer = Server::factory()->online()->create(['uses_edge_proxy' => true]);
    $this->post(route('applications.store', $edgeServer), [
        'name' => 'Edge App',
        'domain' => 'edge.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
    ])->assertRedirect();

    $edgeApp = Application::firstWhere('domain', 'edge.example.com');
    expect($edgeApp->behind_reverse_proxy)->toBeTrue();

    // Native server → new app should default to false.
    $nativeServer = Server::factory()->online()->create(['uses_edge_proxy' => false]);
    $this->post(route('applications.store', $nativeServer), [
        'name' => 'Native App',
        'domain' => 'native.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
    ])->assertRedirect();

    $nativeApp = Application::firstWhere('domain', 'native.example.com');
    expect($nativeApp->behind_reverse_proxy)->toBeFalse();
});

test('show exposes behind_reverse_proxy to the frontend', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'behind_reverse_proxy' => true,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('applications.show', $application));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('application.behind_reverse_proxy', true)
    );
});

test('guests cannot toggle the reverse proxy setting', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->patch(route('applications.reverse-proxy', $application), ['behind_reverse_proxy' => true])
        ->assertRedirect('/login');
});

test('updateReverseProxy persists the flag, rewrites the vhost, reloads nginx, and logs audit', function () {
    mockReverseProxyPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'demo.example.com',
        'app_slug' => 'demo_app',
        'app_type' => 'custom',
        'status' => 'active',
        'behind_reverse_proxy' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('applications.reverse-proxy', $application), ['behind_reverse_proxy' => true])
        ->assertRedirect(route('applications.show', $application));

    expect($application->fresh()->behind_reverse_proxy)->toBeTrue();

    // A render_config job rewrites the vhost, followed by a shell reload.
    $jobs = AgentJob::where('application_id', $application->id)->orderBy('id')->get();
    $render = $jobs->firstWhere('type', 'render_config');
    expect($render)->not->toBeNull();
    expect($render->payload['path'])->toBe(AppTemplates::vhostPath('demo.example.com'));
    expect($render->payload['vars']['behind_reverse_proxy'])->toBe('true');

    $reload = $jobs->firstWhere('type', 'shell');
    expect($reload)->not->toBeNull();
    expect($reload->payload['command'])->toContain('nginx -t');
    expect($reload->payload['command'])->toContain('systemctl reload nginx');

    $log = AuditLog::where('action', 'application.reverse_proxy_updated')->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('submitting the unchanged reverse proxy value is a no-op', function () {
    mockReverseProxyPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'behind_reverse_proxy' => true,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patch(route('applications.reverse-proxy', $application), ['behind_reverse_proxy' => true])
        ->assertRedirect(route('applications.show', $application));

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('vars map always includes behind_reverse_proxy and trusted_proxy_subnet keys', function () {
    // Guards against the agent's missingkey=error render failure.
    $server = Server::factory()->online()->create();

    $on = Application::factory()->create(['server_id' => $server->id, 'behind_reverse_proxy' => true]);
    $vars = AppTemplates::vars($on);
    expect($vars)->toHaveKey('behind_reverse_proxy');
    expect($vars)->toHaveKey('trusted_proxy_subnet');
    expect($vars['behind_reverse_proxy'])->toBe('true');

    $off = Application::factory()->create(['server_id' => $server->id, 'behind_reverse_proxy' => false]);
    expect(AppTemplates::vars($off)['behind_reverse_proxy'])->toBe('false');
});

test('rendered nginx vhost contains trusted-proxy directives only when enabled', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'laravel',
        'domain' => 'proxy.example.com',
        'behind_reverse_proxy' => true,
    ]);
    $renderedOn = renderGoTemplate(AppTemplates::NGINX_VHOST, AppTemplates::vars($app));
    expect($renderedOn)->toContain('set_real_ip_from 0.0.0.0/0');
    expect($renderedOn)->toContain('real_ip_header X-Forwarded-For');
    expect($renderedOn)->toContain('fastcgi_param HTTPS on;');

    $app->forceFill(['behind_reverse_proxy' => false])->save();
    $renderedOff = renderGoTemplate(AppTemplates::NGINX_VHOST, AppTemplates::vars($app));
    expect($renderedOff)->not->toContain('set_real_ip_from');
    expect($renderedOff)->not->toContain('fastcgi_param HTTPS on');
});

test('static and wordpress vhosts get trusted-proxy directives when enabled', function () {
    $server = Server::factory()->online()->create();

    $static = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'static',
        'behind_reverse_proxy' => true,
    ]);
    $staticRendered = renderGoTemplate(AppTemplates::NGINX_VHOST_STATIC, AppTemplates::vars($static));
    expect($staticRendered)->toContain('set_real_ip_from 0.0.0.0/0');
    // Static has no PHP block → no fastcgi HTTPS param.
    expect($staticRendered)->not->toContain('fastcgi_param HTTPS on');

    $wp = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'wordpress',
        'behind_reverse_proxy' => true,
    ]);
    $wpRendered = renderGoTemplate(AppTemplates::NGINX_VHOST_WORDPRESS, AppTemplates::vars($wp));
    expect($wpRendered)->toContain('fastcgi_param HTTPS on;');
});

/**
 * Render a Go text/template the same way the agent does, so tests can assert
 * on the actual bytes that land on the server. Uses a tiny PHP port of the
 * {{if eq .key "val"}}...{{end}} subset the vhost templates rely on.
 */
function renderGoTemplate(string $template, array $vars): string
{
    $out = $template;

    // {{if eq .key "value"}} ... {{end}}  → keep contents if match, else drop.
    $out = preg_replace_callback(
        '/\{\{if eq \.(\w+) "([^"]*)"\}\}(.*?)\{\{end\}\}/s',
        function ($m) use ($vars) {
            [$_, $key, $val, $body] = $m;
            return ($vars[$key] ?? null) === $val ? $body : '';
        },
        $out
    );

    // {{.key}} → value
    $out = preg_replace_callback('/\{\{\.(\w+)\}\}/', fn ($m) => $vars[$m[1]] ?? '', $out);

    // Collapse the blank lines left behind by dropped {{if}} blocks so the
    // "does not contain" assertions aren't fooled by leading indentation.
    return preg_replace('/^\s*$/m', '', $out);
}
