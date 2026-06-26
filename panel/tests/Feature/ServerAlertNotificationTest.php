<?php

use App\Events\ServerAlertResolved;
use App\Events\ServerAlertTriggered;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\ServerAlert;
use App\Models\User;
use App\Notifications\ServerAlertNotification;
use App\Services\ThresholdChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('alert triggered fires ServerAlertTriggered event', function () {
    Event::fake([ServerAlertTriggered::class]);

    $server = Server::factory()->online()->create();
    $checker = new ThresholdChecker;

    $checker->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Event::assertDispatched(ServerAlertTriggered::class);
});

test('alert resolved fires ServerAlertResolved event', function () {
    Event::fake([ServerAlertResolved::class]);

    $server = Server::factory()->online()->create();
    // Pre-create an active alert.
    ServerAlert::create([
        'server_id' => $server->id, 'metric_type' => 'cpu',
        'value' => 95.0, 'threshold' => 90.0, 'message' => 'CPU high',
    ]);

    $checker = new ThresholdChecker;

    // Send normal metrics → should resolve.
    $checker->check($server, [
        'cpu_percent' => 50.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Event::assertDispatched(ServerAlertResolved::class);
});

test('notification is sent to email channel when alert fires', function () {
    Notification::fake();

    $user = User::factory()->create();
    NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'email', 'label' => 'My Email',
        'config' => [], 'enabled' => true,
    ]);

    $server = Server::factory()->online()->create();
    $checker = new ThresholdChecker;

    $checker->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Notification::assertSentTo(
        NotificationChannel::first(),
        ServerAlertNotification::class
    );
});

test('notification is sent to slack channel when alert fires', function () {
    Notification::fake();

    $user = User::factory()->create();
    NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'slack', 'label' => 'Team Slack',
        'config' => ['webhook_url' => 'https://hooks.slack.com/test'],
        'enabled' => true,
    ]);

    $server = Server::factory()->online()->create();
    (new ThresholdChecker)->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Notification::assertSentTo(
        NotificationChannel::where('type', 'slack')->first(),
        ServerAlertNotification::class
    );
});

test('no notification sent to disabled channels', function () {
    Notification::fake();

    $user = User::factory()->create();
    NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'email', 'label' => 'Disabled',
        'config' => [], 'enabled' => false,
    ]);

    $server = Server::factory()->online()->create();
    (new ThresholdChecker)->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Notification::assertNothingSent();
});

test('alert resolved sends a resolved notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'email', 'label' => 'My Email',
        'config' => [], 'enabled' => true,
    ]);

    $server = Server::factory()->online()->create();
    ServerAlert::create([
        'server_id' => $server->id, 'metric_type' => 'cpu',
        'value' => 95.0, 'threshold' => 90.0, 'message' => 'CPU high',
    ]);

    // Send normal metrics → resolve.
    (new ThresholdChecker)->check($server, [
        'cpu_percent' => 50.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    Notification::assertSentTo(
        NotificationChannel::first(),
        fn (ServerAlertNotification $notification, $channels) => true
    );
});

test('cooldown does not fire duplicate events', function () {
    Event::fake([ServerAlertTriggered::class]);

    $server = Server::factory()->online()->create();
    // Pre-create an alert 2 minutes ago (within cooldown).
    ServerAlert::create([
        'server_id' => $server->id, 'metric_type' => 'cpu',
        'value' => 92.0, 'threshold' => 90.0, 'message' => 'CPU high',
        'created_at' => now()->subMinutes(2),
    ]);

    (new ThresholdChecker)->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000, 'mem_used' => 500,
        'disk_total' => 1000, 'disk_used' => 500,
    ]);

    // Cooldown active — no new triggered event.
    Event::assertNotDispatched(ServerAlertTriggered::class);
});
