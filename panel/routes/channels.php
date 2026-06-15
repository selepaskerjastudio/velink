<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Per-server channel for live job progress + presence. This is a single-tenant
// internal panel, so any authenticated user may listen.
Broadcast::channel('server.{serverUuid}', function ($user) {
    return $user !== null;
});
