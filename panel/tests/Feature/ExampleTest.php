<?php

use App\Models\User;

it('redirects the root URL to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('shows the dashboard to authenticated users', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();
});
