<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net: re-deliver agent jobs that got stuck in `dispatched` (e.g. a
// transient gateway pub/sub gap) to servers whose agent is still online.
// Requires the scheduler cron (`* * * * * php artisan schedule:run`).
Schedule::command('jobs:redispatch-stuck --older-than=2')
    ->everyMinute()
    ->withoutOverlapping();
