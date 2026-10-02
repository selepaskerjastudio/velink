<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep stored database-import dumps. The signed download URL expires after
// an hour, so anything left over is a finished (or failed) import.
Artisan::command('dumps:prune', function () {
    $cutoff = now()->subDay()->getTimestamp();
    $pruned = 0;
    foreach (Storage::files('dumps') as $file) {
        if (Storage::lastModified($file) < $cutoff) {
            Storage::delete($file);
            $pruned++;
        }
    }

    if ($pruned > 0) {
        $this->info("Pruned {$pruned} stored database dump(s).");
    }
})->purpose('Prune stored database dumps older than 24 hours');

Schedule::command('dumps:prune')->hourly();

// Safety net: re-deliver agent jobs that got stuck in `dispatched` (e.g. a
// transient gateway pub/sub gap) to servers whose agent is still online.
// Requires the scheduler cron (`* * * * * php artisan schedule:run`).
Schedule::command('jobs:redispatch-stuck --older-than=2')
    ->everyMinute()
    ->withoutOverlapping();
