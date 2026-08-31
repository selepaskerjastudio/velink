<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Per-server channel for live job progress + presence. Job output is streamed
// verbatim over this channel, so subscription has to be scoped the same way
// the HTTP routes are — otherwise a member could watch commands running on a
// server they were never assigned to.
//
// The channel key is a uuid, so this costs one lookup per subscribe. That is
// once per page load (it is a private channel, not per-event), so it is fine.
Broadcast::channel('server.{serverUuid}', function (User $user, string $serverUuid) {
    if (! $user->is_active) {
        return false;
    }

    $server = Server::where('uuid', $serverUuid)->first();

    // Must return a strict bool: returning the model would make Laravel treat
    // it as presence-channel member data and broadcast its attributes.
    return $server !== null && $user->canAccessServer($server);
});
