<?php

use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('notifications.index'))->assertRedirect('/login');
    $this->post(route('notifications.store'))->assertRedirect('/login');
});

test('the notifications page lists channels', function () {
    $user = User::factory()->create();
    NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'slack', 'label' => 'Team Slack',
        'config' => ['webhook_url' => 'https://hooks.slack.com/test'], 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/notifications')
            ->has('channels', 1)
            ->where('channels.0.type', 'slack')
            ->where('channels.0.label', 'Team Slack')
            ->where('channels.0.enabled', true)
        );
});

test('store creates a slack channel with encrypted webhook config', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('notifications.store'), [
            'type' => 'slack',
            'label' => 'Dev Slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX'],
        ])
        ->assertRedirect(route('notifications.index'));

    $channel = NotificationChannel::where('user_id', $user->id)->first();
    expect($channel)->not->toBeNull()
        ->and($channel->type)->toBe('slack')
        ->and($channel->label)->toBe('Dev Slack')
        ->and($channel->config['webhook_url'])->toBe('https://hooks.slack.com/services/T000/B000/XXX')
        ->and($channel->enabled)->toBeTrue();

    expect(AuditLog::where('action', 'notification.channel_added')->exists())->toBeTrue();
});

test('store creates a telegram channel with bot token and chat id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('notifications.store'), [
            'type' => 'telegram',
            'label' => 'Alerts Bot',
            'config' => ['bot_token' => '123:abc', 'chat_id' => '-100123'],
        ])
        ->assertRedirect(route('notifications.index'));

    $channel = NotificationChannel::where('type', 'telegram')->first();
    expect($channel->config['bot_token'])->toBe('123:abc')
        ->and($channel->config['chat_id'])->toBe('-100123');
});

test('store validates channel type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('notifications.index'))
        ->post(route('notifications.store'), [
            'type' => 'carrier-pigeon',
            'label' => 'x',
            'config' => [],
        ])
        ->assertSessionHasErrors('type');
});

test('toggle enables/disables a channel', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'email', 'label' => 'Email',
        'config' => [], 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('notifications.toggle', $channel))
        ->assertRedirect(route('notifications.index'));

    expect($channel->refresh()->enabled)->toBeFalse();

    $this->patch(route('notifications.toggle', $channel));
    expect($channel->refresh()->enabled)->toBeTrue();
});

test('destroy removes a channel', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::create([
        'user_id' => $user->id, 'type' => 'discord', 'label' => 'Discord',
        'config' => ['webhook_url' => 'https://discord.com/api/webhooks/test'], 'enabled' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('notifications.destroy', $channel))
        ->assertRedirect(route('notifications.index'));

    expect(NotificationChannel::find($channel->id))->toBeNull();
});

test('a user cannot toggle or delete a channel owned by someone else', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $channel = NotificationChannel::create([
        'user_id' => $owner->id, 'type' => 'email', 'label' => 'Owner',
        'config' => [], 'enabled' => true,
    ]);

    $this->actingAs($intruder)
        ->patch(route('notifications.toggle', $channel))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('notifications.destroy', $channel))
        ->assertForbidden();
});
