<?php

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\AnsiStripper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────
// AnsiStripper: converts ANSI escape codes to HTML
// ──────────────────────────────────────────────────────

test('ansi stripper removes escape codes and returns plain text', function () {
    $input = "\e[32mHello\e[0m World";
    expect(AnsiStripper::toHtml($input))->toBe('Hello World');
});

test('ansi stripper converts colours to HTML spans', function () {
    $result = AnsiStripper::toHtml("\e[32mSuccess\e[0m", colorize: true);

    expect($result)->toContain('<span')
        ->and($result)->toContain('Success')
        ->and($result)->toContain('</span>');
});

test('ansi stripper handles bold and dim', function () {
    $result = AnsiStripper::toHtml("\e[1mBold\e[0m \e[2mDim\e[0m", colorize: true);

    expect($result)->toContain('Bold')
        ->and($result)->toContain('Dim');
});

test('ansi stripper handles multi-line output', function () {
    $input = "Line 1\e[32m OK\e[0m\nLine 2\e[31m FAIL\e[0m";
    $result = AnsiStripper::toHtml($input, colorize: true);

    expect($result)->toContain('Line 1')
        ->and($result)->toContain('Line 2')
        ->and($result)->toContain('OK')
        ->and($result)->toContain('FAIL');
});

test('ansi stripper handles empty string', function () {
    expect(AnsiStripper::toHtml(''))->toBe('')
        ->and(AnsiStripper::toHtml('', colorize: true))->toBe('');
});

test('ansi stripper strips non-SGR control sequences like cursor moves', function () {
    // composer/npm sometimes emit cursor-hide / erase-line codes.
    $input = "Building\e[?25l\e[2KDone";
    expect(AnsiStripper::toHtml($input))->toBe('BuildingDone');
});

test('ansi stripper renders 256-colour and true-colour codes safely', function () {
    $input = "\e[38;5;82mGreen256\e[0m \e[38;2;255;0;0mTrueRed\e[0m";
    $plain = AnsiStripper::toHtml($input);
    $html = AnsiStripper::toHtml($input, colorize: true);

    // Plain mode strips them entirely (the `\e[0m` resets between words
    // collapse to nothing, leaving the original spacing).
    expect($plain)->toBe('Green256 TrueRed');
    // Colourize mode still renders the text inside a fallback span.
    expect($html)->toContain('Green256')
        ->and($html)->toContain('TrueRed')
        ->and($html)->toContain('<span');
});

// ──────────────────────────────────────────────────────
// DeploymentLogController: dedicated log page (HTTP-layer)
// ──────────────────────────────────────────────────────

function makeDeployment(array $overrides = []): array
{
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create(['server_id' => $server->id]);
    $deployment = Deployment::factory()->create(array_merge([
        'application_id' => $app->id,
        'status' => 'success',
        'log' => "Compiling assets...\nDone.\n",
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
    ], $overrides));

    return [$app, $deployment];
}

test('guests are redirected to the login page', function () {
    [, $deployment] = makeDeployment();

    $this->get(route('deployments.log', $deployment->uuid))->assertRedirect('/login');
});

test('authenticated users can view a deployment log', function () {
    [$app, $deployment] = makeDeployment();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('deployments.log', $deployment->uuid))
        ->assertInertia(fn ($page) => $page
            ->component('deployments/show')
            ->where('deployment.id', $deployment->uuid)
            ->where('deployment.status', 'success')
            ->where('deployment.application_name', $app->name)
            ->where('deployment.application_uuid', $app->uuid)
            ->where('deployment.branch', $deployment->branch)
            ->where('deployment.log', "Compiling assets...\nDone.\n") // ANSI-stripped, newlines preserved
        );
});

test('the log endpoint strips ANSI escape codes from the output', function () {
    [, $deployment] = makeDeployment([
        'log' => "\e[32m✔ Success\e[0m\n\e[31m✗ Error\e[0m",
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('deployments.log', $deployment->uuid))
        ->assertInertia(fn ($page) => $page
            ->where('deployment.log', "✔ Success\n✗ Error")
            ->where('deployment.log_html', function ($value) {
                return str_contains($value, '<span') && str_contains($value, '✔ Success');
            })
        );
});

test('a deployment without a log renders an empty string', function () {
    [, $deployment] = makeDeployment(['status' => 'running', 'log' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('deployments.log', $deployment->uuid))
        ->assertInertia(fn ($page) => $page
            ->where('deployment.log', '')
            ->where('deployment.status', 'running')
        );
});

test('the log endpoint returns previous and next deployment ids', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create(['server_id' => $server->id]);

    $older = Deployment::factory()->create(['application_id' => $app->id, 'created_at' => now()->subHours(3)]);
    $current = Deployment::factory()->create(['application_id' => $app->id, 'created_at' => now()->subHours(2)]);
    $newer = Deployment::factory()->create(['application_id' => $app->id, 'created_at' => now()->subHour()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('deployments.log', $current->uuid))
        ->assertInertia(fn ($page) => $page
            ->where('previousId', $older->uuid)
            ->where('nextId', $newer->uuid)
        );
});
